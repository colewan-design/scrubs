<?php

namespace App\Models;

use App\Http\Controllers\Api\EmailVerificationController;
use App\Notifications\ResetPasswordLink;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * FilamentUser is not decorative. Without it Filament ignores canAccessPanel()
 * entirely and falls back to "local environment only" — which means every
 * signed-in customer can open /admin in development, and nobody at all can
 * open it in production.
 */
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, SoftDeletes;

    /** Canada only — the store ships domestically (§5). */
    public const PROVINCES = [
        'AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT',
    ];

    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'business_name', 'city', 'province',
        'role', 'status', 'customer_price_list_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** V2 seam (§13) — resolved by PricingService ahead of global tiers. */
    public function customerPriceList(): BelongsTo
    {
        return $this->belongsTo(CustomerPriceList::class, 'customer_price_list_id');
    }

    /**
     * Send the reset link to the Nuxt storefront rather than Laravel's default
     * server-rendered route, which does not exist in this application.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    /**
     * Same reason as the password reset above: Laravel's default link points at
     * a server-rendered route this application does not have. Routing it through
     * the controller also keeps the §10 email switch in one place.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationController::class)->sendTo($this);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'staff'], true);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Used by the admin's suspend/reactivate actions (§9). */
    public function suspend(): void
    {
        $this->forceFill(['status' => 'suspended'])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill(['status' => 'active'])->save();
    }

    /**
     * Access to the Filament admin panel. Suspended accounts are locked out
     * even if the role still says admin (§9).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && ! $this->isSuspended();
    }
}
