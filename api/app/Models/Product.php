<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retail_price_cents' => 'integer',
            'wholesale_base_price_cents' => 'integer',
            'has_dual_sizing' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sizeChart(): BelongsTo
    {
        return $this->belongsTo(SizeChart::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function tierPrices(): HasMany
    {
        return $this->hasMany(ProductTierPrice::class);
    }

    /** Products visible to the public: active and published. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }

    /** Total sellable stock across every variant. */
    public function availableStock(): int
    {
        return $this->variants
            ->where('is_active', true)
            ->sum(fn (ProductVariant $v) => $v->availableStock());
    }

    public function isInStock(): bool
    {
        return $this->availableStock() > 0;
    }

    /**
     * The cheapest and dearest a shopper could actually pay for this product,
     * across its sellable variants.
     *
     * Plus sizes commonly carry an upcharge, so the product's own
     * `retail_price_cents` is a default rather than the truth — quoting it on a
     * grid where 3XL costs more would advertise a price the cart will not
     * honour. Variants are eager-loaded on both the listing and detail queries,
     * so this costs no extra round trip.
     *
     * @return array{0:int, 1:int} [min, max]
     */
    public function retailPriceRangeCents(): array
    {
        $prices = $this->variants
            ->where('is_active', true)
            ->map(fn (ProductVariant $v): int => $v->retailPriceCents());

        if ($prices->isEmpty()) {
            return [$this->retail_price_cents, $this->retail_price_cents];
        }

        return [(int) $prices->min(), (int) $prices->max()];
    }

    /** True when variants disagree on price, so the UI must say "from". */
    public function retailPriceVaries(): bool
    {
        [$min, $max] = $this->retailPriceRangeCents();

        return $min !== $max;
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true) ?? $this->images->first();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
