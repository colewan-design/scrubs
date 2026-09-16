<?php

namespace App\Support;

use App\Models\Product;

/**
 * Per-size pricing for a product.
 *
 * Scrubs almost always price by size rather than by individual variant — a 3XL
 * carries an upcharge in every colour — so the admin sets one figure per size
 * and it lands on every variant of that size. Individual variants can still be
 * priced one-off from the Inventory panel; this class is careful not to trample
 * those.
 *
 * There is no separate price table: `product_variants.retail_price_cents` stays
 * the single source of truth, because that is the column the cart already
 * charges from (`ProductVariant::retailPriceCents()`). Adding a parallel table
 * would let the two disagree.
 */
class VariantPricing
{
    /**
     * Current price per size, for hydrating the form.
     *
     * A size maps to a figure only when all of its active variants agree. If
     * they disagree — because someone priced one colour by hand — the size
     * reports null rather than silently picking one, so that saving the form
     * does not flatten the exception.
     *
     * @return array<int, int|null> size_id => cents (null = product default, or mixed)
     */
    public static function bySize(Product $product): array
    {
        return $product->variants()
            ->where('is_active', true)
            ->get()
            ->groupBy('size_id')
            ->map(function ($variants) {
                $distinct = $variants->pluck('retail_price_cents')->unique();

                return $distinct->count() === 1 ? $distinct->first() : null;
            })
            ->all();
    }

    /**
     * Report which sizes hold a mix of prices, so the form can say so instead of
     * showing a misleading blank.
     *
     * @return array<int> size_ids
     */
    public static function mixedSizes(Product $product): array
    {
        return $product->variants()
            ->where('is_active', true)
            ->get()
            ->groupBy('size_id')
            ->filter(fn ($variants) => $variants->pluck('retail_price_cents')->unique()->count() > 1)
            ->keys()
            ->all();
    }

    /**
     * Apply submitted per-size prices, touching only the sizes whose figure the
     * admin actually changed.
     *
     * Comparing against what was hydrated is what protects hand-priced
     * variants: an untouched size is left exactly as it was, mixed prices and
     * all. Without that, every product save would overwrite them.
     *
     * @param  array<int, int|null>  $submitted  size_id => cents (null = use product price)
     * @param  array<int, int|null>  $original  the map the form was filled from
     * @return int number of variants repriced
     */
    public static function apply(Product $product, array $submitted, array $original): int
    {
        $repriced = 0;

        foreach ($submitted as $sizeId => $cents) {
            $sizeId = (int) $sizeId;
            $cents = ($cents === '' || $cents === null) ? null : (int) $cents;

            // array_key_exists, not ??, because null is a meaningful value here
            // (it means "fall back to the product price").
            $was = array_key_exists($sizeId, $original) ? $original[$sizeId] : false;

            if ($was !== false && $was === $cents) {
                continue;
            }

            $repriced += $product->variants()
                ->where('is_active', true)
                ->where('size_id', $sizeId)
                ->update(['retail_price_cents' => $cents]);
        }

        return $repriced;
    }
}
