<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in customer, as the storefront sees themselves (§3, §8).
 *
 * One shape for registration, login, /auth/me and every profile update, so the
 * Nuxt auth store never has to reconcile two versions of the same user.
 *
 * @property-read User $resource
 */
class AccountResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'business_name' => $user->business_name,
            'city' => $user->city,
            'province' => $user->province,

            // §8 "account status". Sent rather than implied: a customer whose
            // account has been restricted should be able to see that it has.
            'status' => $user->status,
            'email_verified' => $user->hasVerifiedEmail(),

            /*
             * A Google-created account has no password at all (see the
             * nullable-password migration). The account screen offers "Set a
             * password" rather than "Change password" for those, and must not
             * ask for a current password that does not exist.
             */
            'has_password' => $user->password !== null,
            'auth_providers' => $user->socialAccounts()->pluck('provider')->all(),

            'member_since' => $user->created_at?->toDateString(),

            // Signing in is what makes wholesale pricing visible (§3).
            'wholesale_unlocked' => true,
        ];
    }
}
