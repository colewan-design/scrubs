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
    public const PROVIDER_ETRANSFER = 'etransfer';
    public const PROVIDER_MANUAL = 'manual';

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
}
