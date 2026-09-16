<?php

namespace App\Support;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a product's variants from the colours and sizes chosen on the
 * product form, so an admin picks "5 colours x 7 sizes" once instead of adding
 * 35 rows by hand.
 *
 * The one rule that shapes everything here: **variants are never deleted.**
 * `order_items` and `cart_items` both reference `product_variants`, so removing
 * a row would orphan order history. Dropping a colour deactivates its variants
 * instead; re-adding the colour revives the same rows, stock and all.
 */
class VariantMatrix
{
    /**
     * Reconcile a product's variants against the selected option values.
     *
     * @param  array<int>  $colorIds
     * @param  array<int>  $sizeIds
     * @param  array<int>  $secondarySizeIds  Bottom sizes; only used when the product has dual sizing.
     * @return array{created:int, reactivated:int, deactivated:int}
     */
    public static function sync(
        Product $product,
        array $colorIds,
        array $sizeIds,
        array $secondarySizeIds = [],
    ): array {
        $colorIds = array_values(array_unique(array_filter($colorIds)));
        $sizeIds = array_values(array_unique(array_filter($sizeIds)));
        $secondarySizeIds = array_values(array_unique(array_filter($secondarySizeIds)));

        // Nothing selected is treated as "leave it alone" rather than "deactivate
        // everything" — the destructive reading of an empty form is never what
        // an admin means, and a half-loaded form should not wipe a catalogue.
        if ($colorIds === [] || $sizeIds === []) {
            return ['created' => 0, 'reactivated' => 0, 'deactivated' => 0];
        }

        $dual = (bool) $product->has_dual_sizing;

        // With dual sizing every top size pairs with every bottom size; without
        // it there is a single NULL secondary, stored as 0 in the variant key.
        $secondaries = $dual
            ? ($secondarySizeIds !== [] ? $secondarySizeIds : $sizeIds)
            : [null];

        $colors = Color::whereIn('id', $colorIds)->get()->keyBy('id');
        $sizes = Size::whereIn('id', array_unique([...$sizeIds, ...$secondarySizeIds]))->get()->keyBy('id');

        $existing = $product->variants()->get()->keyBy(
            fn (ProductVariant $v) => self::key($v->color_id, $v->size_id, $v->secondary_size_id)
        );

        $wanted = [];
        $created = $reactivated = 0;

        DB::transaction(function () use (
            $product, $colorIds, $sizeIds, $secondaries, $colors, $sizes,
            $existing, &$wanted, &$created, &$reactivated
        ) {
            foreach ($colorIds as $colorId) {
                foreach ($sizeIds as $sizeId) {
                    foreach ($secondaries as $secondaryId) {
                        $key = self::key($colorId, $sizeId, $secondaryId);
                        $wanted[$key] = true;

                        $variant = $existing->get($key);

                        if ($variant) {
                            // Revive a previously dropped combination rather than
                            // creating a duplicate — its stock and ledger survive.
                            if (! $variant->is_active) {
                                $variant->update(['is_active' => true]);
                                $reactivated++;
                            }

                            continue;
                        }

                        $product->variants()->create([
                            'color_id' => $colorId,
                            'size_id' => $sizeId,
                            'secondary_size_id' => $secondaryId,
                            'sku' => self::sku(
                                $product,
                                $colors->get($colorId)?->name ?? 'XXX',
                                $sizes->get($sizeId)?->name ?? '?',
                                $secondaryId ? ($sizes->get($secondaryId)?->name) : null,
                            ),
                            // New combinations start empty. Stock arrives through
                            // the inventory ledger, never as a silent column write.
                            'stock_qty' => 0,
                            'reserved_qty' => 0,
                            'low_stock_threshold' => $product->low_stock_threshold,
                            'is_active' => true,
                        ]);

                        $created++;
                    }
                }
            }
        });

        // Deactivate whatever the admin removed. Kept, not deleted (see above).
        $deactivated = 0;

        foreach ($existing as $key => $variant) {
            if (! isset($wanted[$key]) && $variant->is_active) {
                $variant->update(['is_active' => false]);
                $deactivated++;
            }
        }

        return ['created' => $created, 'reactivated' => $reactivated, 'deactivated' => $deactivated];
    }

    /**
     * Matches the convention already in the catalogue:
     * `BSD-W-CLS-NAV-XS` = base SKU, first three letters of the colour, size.
     * Dual-sized products append the bottom size: `...-NAV-M-L`.
     */
    public static function sku(Product $product, string $colorName, string $sizeName, ?string $secondarySizeName = null): string
    {
        $parts = [
            $product->base_sku,
            Str::upper(Str::substr(Str::slug($colorName, ''), 0, 3)),
            Str::upper($sizeName),
        ];

        if ($secondarySizeName !== null) {
            $parts[] = Str::upper($secondarySizeName);
        }

        $sku = implode('-', $parts);

        // `sku` is unique across the table, so two colours that share their first
        // three letters ("Navy" / "Natural") would otherwise collide.
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::where('sku', $candidate)->exists()) {
            $candidate = $sku.'-'.$suffix++;
        }

        return $candidate;
    }

    /** The option values currently live on a product, for hydrating the form. */
    public static function currentSelection(Product $product): array
    {
        $active = $product->variants()->where('is_active', true)->get();

        return [
            'variant_color_ids' => $active->pluck('color_id')->filter()->unique()->values()->all(),
            'variant_size_ids' => $active->pluck('size_id')->filter()->unique()->values()->all(),
            'variant_secondary_size_ids' => $active->pluck('secondary_size_id')->filter()->unique()->values()->all(),
        ];
    }

    private static function key(?int $colorId, ?int $sizeId, ?int $secondaryId): string
    {
        return implode('-', [$colorId, $sizeId, $secondaryId ?? 0]);
    }
}
