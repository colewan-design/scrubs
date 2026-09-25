<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment attempt against an order.
 *
 * `provider` is deliberately open: `stripe` once the merchant account is
 * approved, `etransfer` for the offline flow §4 needs, `manual` for anything an
 * administrator records by hand. `raw_response` keeps the provider's own
 * payload so a disputed charge can be reconstructed later.
 */
class Payment extends Model
{
    use HasFactory;

    public const PROVIDER_STRIPE = 'stripe';

    public const PROVIDER_PAYPAL = 'paypal';

    public const PROVIDER_ETRANSFER = 'etransfer';

    public const PROVIDER_MANUAL = 'manual';

    /** The methods checkout may be asked for. Anything else is rejected there. */
    public const CHECKOUT_PROVIDERS = [
        self::PROVIDER_PAYPAL,
        self::PROVIDER_ETRANSFER,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'raw_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundableCents(): int
    {
        return max(0, $this->amount_cents - (int) $this->refunds()->sum('amount_cents'));
    }

    /**
     * The PayPal capture this payment was settled by, which is what a refund is
     * issued against. `provider_reference` holds the PayPal *order* id until the
     * capture succeeds and replaces it, so the stored capture id is preferred.
     */
    public function paypalCaptureId(): ?string
    {
        if ($this->provider !== self::PROVIDER_PAYPAL) {
            return null;
        }

        $captureId = $this->raw_response['capture_id'] ?? null;

        return $captureId ?: ($this->status === self::STATUS_SUCCEEDED ? $this->provider_reference : null);
    }
}
