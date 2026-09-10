<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account management — §8's "saved contact information".
 *
 * The customer owns their own contact details; none of this needs an admin.
 * Two things are deliberately awkward, on purpose:
 *
 *  1. Changing the email address clears verification and re-sends the link. An
 *     address nobody has proven ownership of must not inherit the verified
 *     mark from the one it replaced.
 *
 *  2. Setting a password on a Google-created account does not ask for a current
 *     one, because there isn't one — but it still requires a live session, so
 *     it is not a way to take over an account you merely know the email for.
 */
class AccountController extends Controller
{
    public function __construct(protected EmailVerificationController $verification) {}

    /** Update contact details (§8). */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:30'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'size:2'],
        ]);

        $emailChanged = ! hash_equals($user->email, $data['email']);

        $user->fill($data);

        if ($emailChanged) {
            // Not mass-assignable, by design — see the User model's $fillable.
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        $verificationSent = $emailChanged && $this->verification->sendTo($user);

        return response()->json([
            'user' => new AccountResource($user),
            'email_changed' => $emailChanged,
            'verification_sent' => $verificationSent,
        ]);
    }

    /**
     * Change — or, for a social-only account, set — the password (§8).
     *
     * Rotating remember_token is the part that matters: it invalidates the
     * "keep me signed in" cookie everywhere. If the reason for changing a
     * password is that somebody else has it, leaving their cookie working
     * would defeat the point. This session is then re-issued so the customer
     * is not signed out of the tab they are standing in.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasPassword = $user->password !== null;

        $data = $request->validate([
            'current_password' => [Rule::requiredIf($hasPassword), 'nullable', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($hasPassword && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // Named explicitly: auth:sanctum has already made itself the default
        // guard by the time a controller runs, and a RequestGuard cannot log
        // anybody in. The session guard is the one holding this login.
        Auth::guard('web')->login($user, remember: true);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => new AccountResource($user),
            'message' => $hasPassword
                ? 'Your password has been changed.'
                : 'Your password is set. You can now sign in with your email as well as Google.',
        ]);
    }
}
