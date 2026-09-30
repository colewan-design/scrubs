<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in — §3, §8.
 *
 * Google is the only social provider. Apple was dropped from scope on
 * 2026-09-04: it carries a US$99/yr developer account and a client secret that
 * is a signed JWT needing rotation every six months, for a second button on the
 * same screen. `provider` stays a string end to end, so adding one later is
 * configuration plus a driver, not a schema change.
 *
 * The brief calls Google "strongly preferred as a launch feature", and it does
 * double duty right now: an account created this way never needs the password
 * reset that email delivery is currently blocking.
 *
 * The flow is a browser redirect, not an API call, because that is what OAuth
 * is. The customer leaves for Google and comes back to the callback, which
 * establishes the same first-party session cookie a password login would and
 * then bounces them to the storefront.
 *
 * ACCOUNT LINKING: matching on a verified provider email is deliberate. Google
 * has already proven the customer controls that address, so an existing
 * password account with the same email is the same person — refusing to link
 * would strand them with two accounts and one order history.
 */
class SocialAuthController extends Controller
{
    /** Providers with a slot in config/services.php. */
    protected const SUPPORTED = ['google'];

    /** Session key holding where to send the customer once they are back. */
    protected const RETURN_TO = 'social_auth.return_to';

    public function redirect(string $provider, Request $request): RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED, true), 404);

        if (! $this->configured($provider)) {
            return $this->backToStorefront(
                'unavailable',
                ucfirst($provider).' sign-in is not configured yet.',
            );
        }

        // Checkout sends a signed-out customer here with ?redirect=/checkout, and
        // they should land back on checkout, not the account home. Remembered in
        // the session because Google will not carry it through the round trip.
        if ($request->hasSession()) {
            $returnTo = $this->safeReturnPath($request->query('redirect'));

            $returnTo === null
                ? $request->session()->forget(self::RETURN_TO)
                : $request->session()->put(self::RETURN_TO, $returnTo);
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED, true), 404);

        if (! $this->configured($provider)) {
            return $this->backToStorefront('unavailable', ucfirst($provider).' sign-in is not configured yet.');
        }

        // The customer can decline consent, and the provider can be down. Both
        // come back here and neither should be a stack trace.
        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            Log::warning('Social sign-in failed.', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->backToStorefront('failed', 'We could not complete that sign-in. Please try again.');
        }

        if (! $oauthUser->getEmail()) {
            return $this->backToStorefront(
                'no-email',
                'That account did not share an email address, which we need to create your account.',
            );
        }

        $user = $this->resolveUser($provider, $oauthUser);

        if ($user->isSuspended()) {
            return $this->backToStorefront('suspended', 'This account has been suspended. Please contact us.');
        }

        Auth::login($user, remember: true);

        $returnTo = null;

        if ($request->hasSession()) {
            $returnTo = $request->session()->pull(self::RETURN_TO);
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->backToStorefront(returnTo: $this->safeReturnPath($returnTo));
    }

    /**
     * Only a path on our own storefront. Anything else — a full URL, or a
     * protocol-relative "//evil.test" — would turn sign-in into an open
     * redirect, so it is dropped rather than followed.
     */
    protected function safeReturnPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 500) {
            return null;
        }

        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }

    /**
     * Find the account this identity belongs to, or create one.
     *
     * Order matters: the provider link is checked first so a customer who has
     * since changed their email on our side still lands on their own account.
     */
    protected function resolveUser(string $provider, mixed $oauthUser): User
    {
        return DB::transaction(function () use ($provider, $oauthUser): User {
            $link = SocialAccount::where('provider', $provider)
                ->where('provider_user_id', (string) $oauthUser->getId())
                ->first();

            if ($link && $link->user) {
                return $link->user;
            }

            $user = User::where('email', $oauthUser->getEmail())->first();

            if (! $user) {
                $user = User::create([
                    'name' => $oauthUser->getName() ?: $oauthUser->getNickname() ?: 'Customer',
                    'email' => $oauthUser->getEmail(),
                    // No password: this account authenticates through the
                    // provider. See the nullable-password migration.
                    'password' => null,
                    'role' => 'customer',
                    'status' => 'active',
                ]);

                // Deliberately not mass-assigned. `email_verified_at` is kept out
                // of $fillable so no registration payload can forge it; the
                // provider having verified the address is what earns it here.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            SocialAccount::updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => (string) $oauthUser->getId()],
                ['user_id' => $user->id, 'avatar_url' => $oauthUser->getAvatar()],
            );

            return $user;
        });
    }

    /**
     * Which providers the storefront should actually offer (§3).
     *
     * The sign-in page and the unlock dialog read this rather than hardcoding
     * two buttons, so a provider that has not been set up is simply absent
     * instead of being a button that apologises after the click.
     */
    public function available(): JsonResponse
    {
        $providers = [];

        foreach (self::SUPPORTED as $provider) {
            if ($this->configured($provider)) {
                $providers[] = $provider;
            }
        }

        return response()->json(['providers' => $providers]);
    }

    /**
     * Configured means credentials *and* a driver.
     *
     * The driver check outlives Apple's removal: Socialite ships drivers for
     * only some providers, and without this, adding credentials for one it does
     * not know turns a graceful "not configured yet" into an
     * InvalidArgumentException thrown mid-redirect — the failure mode being
     * guarded against everywhere else in this class.
     */
    protected function configured(string $provider): bool
    {
        if (! config("services.{$provider}.client_id") || ! config("services.{$provider}.client_secret")) {
            return false;
        }

        try {
            Socialite::driver($provider);
        } catch (InvalidArgumentException $e) {
            Log::warning('Social provider is configured but has no driver installed.', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * OAuth ends in the browser, so every outcome is a redirect back to the
     * storefront — success silently, failure with a code the sign-in page can
     * turn into a message.
     */
    protected function backToStorefront(
        ?string $error = null,
        ?string $message = null,
        ?string $returnTo = null,
    ): RedirectResponse {
        $base = rtrim(config('app.frontend_url'), '/');

        if ($error === null) {
            return redirect()->away($base.($returnTo ?? '/account'));
        }

        return redirect()->away(
            $base.'/account/login?'.http_build_query(array_filter([
                'error' => $error,
                'message' => $message,
            ]))
        );
    }
}
