<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Notifications\VerifyEmailLink;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Email verification — §8.
 *
 * Verification here is *soft*. The brief asks for a fast, low-friction signup
 * and makes wholesale eligibility a function of order size, so an unconfirmed
 * address does not gate the catalogue, the cart, or wholesale pricing. What it
 * gates is trust in the address itself: the storefront shows an unverified
 * customer a prompt, and the admin can see at a glance which addresses have
 * been proven.
 *
 * It is also inert while §10's "Send order emails" switch is off. Sending a
 * confirmation link through an unconfigured mailer would either throw inside
 * registration or, worse, silently drop — leaving a customer waiting on a mail
 * that was never going to arrive. sendTo() reports whether it actually sent,
 * and every caller tells the customer the truth.
 */
class EmailVerificationController extends Controller
{
    /** Long enough to survive a spam folder and a night's sleep. */
    protected const EXPIRES_MINUTES = 60 * 24;

    public function __construct(protected Settings $settings) {}

    /**
     * Confirm an address from the emailed link.
     *
     * Deliberately unauthenticated. The signature proves the link came from us
     * and the hash proves it was issued for the address the account holds right
     * now — so changing the email invalidates any link still in flight for the
     * old one. Requiring a session on top of that would only mean the link
     * fails when opened on a different device, which is where mail is read.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->backToStorefront('link-expired');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->backToStorefront('link-invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->backToStorefront('already-verified');
        }

        $user->markEmailAsVerified();

        return $this->backToStorefront('verified');
    }

    /** Re-send the link to the signed-in customer's current address (§8). */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'sent' => false,
                'message' => 'That address is already confirmed.',
                'user' => new AccountResource($user),
            ]);
        }

        if (! $this->enabled()) {
            return response()->json([
                'sent' => false,
                'message' => 'Email confirmation is not switched on yet. '
                    .'Your account works normally in the meantime.',
            ], 503);
        }

        return response()->json([
            'sent' => $this->sendTo($user),
            'message' => 'Confirmation link sent. Check your inbox.',
        ]);
    }

    /**
     * Issue a fresh link. Returns false when nothing was sent, so the caller
     * can say so rather than promising an email that is not coming.
     */
    public function sendTo(User $user): bool
    {
        if ($user->hasVerifiedEmail() || ! $this->enabled()) {
            return false;
        }

        try {
            $user->notify(new VerifyEmailLink($this->linkFor($user), self::EXPIRES_MINUTES));
        } catch (Throwable $e) {
            // Same rule as OrderNotifier: a failing mail host must never break
            // the registration or profile save that triggered it.
            Log::error('Verification email failed to send.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    protected function linkFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(self::EXPIRES_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    /** §10's master switch: nothing sends until the client turns email on. */
    protected function enabled(): bool
    {
        return $this->settings->bool('notifications.enabled');
    }

    /** The link is clicked in a browser, so every outcome is a redirect. */
    protected function backToStorefront(string $status): RedirectResponse
    {
        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/account?'.http_build_query(['verify' => $status])
        );
    }
}
