<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of one line at the moment of purchase.
 *
 * The duplicated product name, SKU, colour and size are deliberate: renaming a
 * product or editing a price in admin must never alter a historical order or a
 * previously issued invoice.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_retail_cents' => 'integer',
            'unit_price_cents' => 'integer',
            'line_discount_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Nullable: a variant may be deleted long after the order was placed. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** "Ceil Blue / M", or "Ceil Blue / M top / L bottom" for a dual-sized set. */
    public function variantLabel(): string
    {
        $parts = array_filter([
            $this->color_name,
            $this->secondary_size_name
                ? "{$this->size_name} top / {$this->secondary_size_name} bottom"
                : $this->size_name,
        ]);

        return implode(' / ', $parts);
    }
}
