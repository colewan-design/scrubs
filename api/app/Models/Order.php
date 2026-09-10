<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A placed order (§7).
 *
 * `status` is the customer-facing label — exactly the eight names §7 asks for —
 * but it is DERIVED from payment_status + fulfillment_status by OrderService,
 * never set by hand. Storing a single hand-maintained status cannot represent a
 * shipped-then-refunded order; deriving it can, while still showing the client
 * the vocabulary they asked for.
 */
class Order extends Model
{
    use HasFactory;

    /** The eight §7 labels. */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_FAILED = 'failed';

    public const FULFILLMENT_UNFULFILLED = 'unfulfilled';
    public const FULFILLMENT_PROCESSING = 'processing';
    public const FULFILLMENT_READY_FOR_PICKUP = 'ready_for_pickup';
    public const FULFILLMENT_SHIPPED = 'shipped';
    public const FULFILLMENT_COMPLETED = 'completed';
    public const FULFILLMENT_CANCELLED = 'cancelled';

    public const TYPE_SHIP = 'ship';
    public const TYPE_PICKUP = 'pickup';

    /** Human labels for the eight statuses, for display and for Filament. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING_PAYMENT => 'Pending Payment',
        self::STATUS_PAID => 'Paid',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_READY_FOR_PICKUP => 'Ready for Pickup',
        self::STATUS_SHIPPED => 'Shipped',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_REFUNDED => 'Refunded',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'shipping_cents' => 'integer',
            'tax_cents' => 'integer',
            'grand_total_cents' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_SHIPPING);
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_BILLING);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(OrderTax::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isPickup(): bool
    {
        return $this->fulfillment_type === self::TYPE_PICKUP;
    }

    /**
     * Cancellable while nothing has physically left. Once an order is shipped,
     * cancelling is a refund conversation, not a status change.
     */
    public function isCancellable(): bool
    {
        return ! in_array($this->fulfillment_status, [
            self::FULFILLMENT_SHIPPED,
            self::FULFILLMENT_COMPLETED,
            self::FULFILLMENT_CANCELLED,
        ], true);
    }

    /** Orders are addressed by number in every URL — never by database id. */
    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    public function totalRefundedCents(): int
    {
        return (int) $this->refunds()->sum('amount_cents');
    }

    /**
     * What is still refundable — the paid total less anything already returned.
     *
     * Guards against over-refunding across several partial refunds, where each
     * one looks reasonable on its own but the sum exceeds what was charged.
     */
    public function outstandingRefundableCents(): int
    {
        return max(0, $this->grand_total_cents - $this->totalRefundedCents());
    }
}
