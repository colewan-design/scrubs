<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A wholesale pricing tier — requirements §3.
 *
 * The brief uses "MOQ" and "qualifying order value" interchangeably, so a tier
 * can carry a dollar threshold, a quantity threshold, or both. qualify_mode
 * decides whether both must be met ('all') or either is enough ('any').
 */
class PricingTier extends Model
{
    use HasFactory;

    public const DISCOUNT_PERCENT = 'percent';            // basis points
    public const DISCOUNT_FIXED_OFF = 'fixed_amount_off'; // cents off each unit
    public const DISCOUNT_ABSOLUTE = 'absolute_price';    // cents, becomes the unit price

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_subtotal_cents' => 'integer',
            'min_qty' => 'integer',
            'discount_value' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductTierPrice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Does a cart of this retail value and unit count qualify?
     *
     * CRITICAL: $retailSubtotalCents must always be the *pre-discount* retail
     * subtotal. Evaluating against the discounted total lets a cart oscillate —
     * qualify, get cheaper, fall below the threshold, un-qualify, and back again.
     * A discount must never revoke the qualification that produced it.
     */
    public function qualifies(int $retailSubtotalCents, int $totalQty): bool
    {
        $hasSubtotalRule = $this->min_subtotal_cents !== null;
        $hasQtyRule = $this->min_qty !== null;

        // A tier with no thresholds at all would qualify every cart, which is
        // never the intent — treat it as misconfigured and never matching.
        if (! $hasSubtotalRule && ! $hasQtyRule) {
            return false;
        }

        $meetsSubtotal = $hasSubtotalRule && $retailSubtotalCents >= $this->min_subtotal_cents;
        $meetsQty = $hasQtyRule && $totalQty >= $this->min_qty;

        if ($this->qualify_mode === 'all') {
            // Only rules that are actually set need to be satisfied.
            return (! $hasSubtotalRule || $meetsSubtotal)
                && (! $hasQtyRule || $meetsQty);
        }

        return $meetsSubtotal || $meetsQty;
    }

    /**
     * Apply this tier's discount rule to a retail unit price.
     * Never returns a negative price.
     */
    public function applyTo(int $retailUnitCents): int
    {
        $price = match ($this->discount_type) {
            self::DISCOUNT_PERCENT => (int) round($retailUnitCents * (10000 - $this->discount_value) / 10000),
            self::DISCOUNT_FIXED_OFF => $retailUnitCents - $this->discount_value,
            self::DISCOUNT_ABSOLUTE => $this->discount_value,
            default => $retailUnitCents,
        };

        return max(0, $price);
    }

    /** How much more is needed to reach this tier, for the upsell prompt. */
    public function gapFrom(int $retailSubtotalCents, int $totalQty): array
    {
        return [
            'subtotal_cents' => $this->min_subtotal_cents !== null
                ? max(0, $this->min_subtotal_cents - $retailSubtotalCents)
                : null,
            'qty' => $this->min_qty !== null
                ? max(0, $this->min_qty - $totalQty)
                : null,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
