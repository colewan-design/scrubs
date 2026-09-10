<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\Notifications\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Email/password authentication — §3, §8.
 *
 * Registration is deliberately short. The brief's priority is "a fast,
 * low-friction signup", and crucially a customer does NOT need to prove they
 * operate a registered business: the MOQ is the wholesale qualification.
 */
class AuthController extends Controller
{
    public function __construct(protected EmailVerificationController $verification) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            // Optional (§3) — collected for context, never used for gating.
            'business_name' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'size:2'],
        ]);

        $user = User::create($data + ['role' => 'customer', 'status' => 'active']);

        Auth::login($user, remember: true);
        $this->regenerateSession($request);

        /*
         * Verification is soft (§8): the account is usable, the cart survives,
         * and wholesale pricing is already unlocked. The link is an invitation
         * to confirm the address, never a gate — asking someone to go and read
         * an email before they can see the price they came for would undo the
         * low-friction signup the brief asks for.
         */
        return response()->json([
            'user' => new AccountResource($user),
            'verification_sent' => $this->verification->sendTo($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $data['email'], 'password' => $data['password']],
            $data['remember'] ?? true
        )) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $user = $request->user();

        // A suspended account must not be able to hold a session (§9).
        if ($user->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been suspended. Please contact us.',
            ]);
        }

        $this->regenerateSession($request);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => new AccountResource($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user() ? new AccountResource($request->user()) : null,
        ]);
    }

    /**
     * Start a password reset (§8).
     *
     * The response never reveals whether the address is registered — that would
     * turn this endpoint into a way to enumerate customers. The one exception is
     * when email is switched off entirely: claiming to have sent a message we
     * cannot send would leave the customer waiting for a mail that never comes,
     * so that case says so plainly and points them at support.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        if (! app(OrderNotifier::class)->enabled()) {
            return response()->json([
                'message' => 'Password reset by email is not available yet. '
                    .'Please contact us and we will reset your password for you.',
                'available' => false,
            ], 503);
        }

        Password::sendResetLink($data);

        return response()->json([
            'message' => 'If that email is registered, a reset link is on its way.',
            'available' => true,
        ]);
    }

    /** Complete a password reset with the token from the emailed link (§8). */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. You can sign in now.']);
    }

    /**
     * Sanctum only attaches a session for requests from a configured stateful
     * origin. A non-browser client (a health check, an integration test) has no
     * session, and must not trigger a 500 on an otherwise valid login.
     */
    protected function regenerateSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
