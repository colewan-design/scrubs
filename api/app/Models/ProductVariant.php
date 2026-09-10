<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retail_price_cents' => 'integer',
            'stock_qty' => 'integer',
            'reserved_qty' => 'integer',
            'weight_grams' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A composite unique index cannot guard this combination, because SQL
        // treats NULLs as distinct and secondary_size_id is nullable — two rows
        // with the same colour/size and a NULL secondary would both be accepted.
        // Deriving a non-null key gives us a real uniqueness constraint.
        static::saving(function (ProductVariant $variant) {
            $variant->variant_key = implode('-', [
                $variant->product_id,
                $variant->color_id,
                $variant->size_id,
                $variant->secondary_size_id ?? 0,
            ]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** The size, or the *top* size when the product has dual sizing (Q1). */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    /** The bottom size — only used when the product has dual sizing. */
    public function secondarySize(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'secondary_size_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * What can actually be sold right now. Reserved units belong to checkouts
     * that are in flight but not yet paid.
     */
    public function availableStock(): int
    {
        return max(0, $this->stock_qty - $this->reserved_qty);
    }

    public function isInStock(): bool
    {
        return $this->availableStock() > 0;
    }

    public function isLowStock(): bool
    {
        $threshold = $this->low_stock_threshold
            ?? $this->product?->low_stock_threshold
            ?? 5;

        return $this->availableStock() > 0 && $this->availableStock() <= $threshold;
    }

    /** Variant price overrides the product price when set. */
    public function retailPriceCents(): int
    {
        return $this->retail_price_cents ?? $this->product->retail_price_cents;
    }

    /** Falls back to the product weight when a per-variant weight is not captured. */
    public function shippingWeightGrams(): ?int
    {
        return $this->weight_grams;
    }

    public function displayName(): string
    {
        $parts = array_filter([
            $this->color?->name,
            $this->size?->name,
            $this->secondarySize?->name,
        ]);

        return implode(' / ', $parts);
    }
}
