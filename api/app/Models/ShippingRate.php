<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a zone's rate table (§5).
 *
 * Every band is nullable at both ends, so a rate can be bounded by weight, by
 * order value, by both, or by neither. A null bound means "no limit in this
 * direction" — not zero.
 */
class ShippingRate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_weight_grams' => 'integer',
            'max_weight_grams' => 'integer',
            'min_subtotal_cents' => 'integer',
            'max_subtotal_cents' => 'integer',
            'rate_cents' => 'integer',
            'is_free' => 'boolean',
            'delivery_days_min' => 'integer',
            'delivery_days_max' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Bounds are inclusive at the minimum and exclusive at the maximum. */
    public function matches(int $weightGrams, int $subtotalCents): bool
    {
        return ($this->min_weight_grams === null || $weightGrams >= $this->min_weight_grams)
            && ($this->max_weight_grams === null || $weightGrams < $this->max_weight_grams)
            && ($this->min_subtotal_cents === null || $subtotalCents >= $this->min_subtotal_cents)
            && ($this->max_subtotal_cents === null || $subtotalCents < $this->max_subtotal_cents);
    }

    public function costCents(): int
    {
        return $this->is_free ? 0 : $this->rate_cents;
    }
}
