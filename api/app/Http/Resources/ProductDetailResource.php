<?php

namespace App\Http\Resources;

use App\Services\Pricing\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wholesale figures are NEVER included for a guest (§3), on the same terms as
 * ProductCardResource: a logged-out visitor gets `wholesale_locked: true` and
 * no numbers at all, so nothing leaks through the network tab.
 */
class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Resolved once — the ladder is read three times below.
        $ladder = $user !== null
            ? app(PricingService::class)->ladderFor($this->resource, $user)
            : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'base_sku' => $this->base_sku,
            'product_type' => $this->product_type,
            'has_dual_sizing' => (bool) $this->has_dual_sizing,
            'short_description' => $this->short_description,

            // The three collapsible (+/-) panels required by §2.
            'panels' => array_values(array_filter([
                $this->description ? ['key' => 'description', 'label' => 'Description', 'body' => $this->description] : null,
                $this->materials ? ['key' => 'materials', 'label' => 'Materials', 'body' => $this->materials] : null,
                $this->dimensions_fit ? ['key' => 'fit', 'label' => 'Dimensions & Fit', 'body' => $this->dimensions_fit] : null,
            ])),

            'retail_price' => MoneyResource::make($this->retail_price_cents),
            // The band the variants actually span. The page shows the selected
            // variant's price once a size is picked, and "from <min>" before
            // that — quoting the product default would misprice any product
            // carrying a plus-size upcharge.
            'retail_price_from' => $this->when(
                $this->relationLoaded('variants'),
                fn () => MoneyResource::make($this->retailPriceRangeCents()[0]),
            ),
            'retail_price_to' => $this->when(
                $this->relationLoaded('variants'),
                fn () => MoneyResource::make($this->retailPriceRangeCents()[1]),
            ),
            'retail_price_varies' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->retailPriceVaries(),
                false,
            ),
            'wholesale_locked' => $user === null,

            // The entry rung, matching the grid. Deeper tiers are in
            // `wholesale_tiers` below, where the thresholds are shown with them.
            'wholesale_from' => $this->when(
                $ladder !== [],
                fn () => MoneyResource::make($ladder[0]['unit_price_cents'])
            ),

            // The full ladder, so a member can see what each tier is worth on
            // THIS product before committing anything to the cart.
            'wholesale_tiers' => $this->when($ladder !== [], fn () => array_map(fn (array $rung) => [
                'id' => $rung['tier']->id,
                'name' => $rung['tier']->name,
                'slug' => $rung['tier']->slug,
                'min_subtotal_cents' => $rung['tier']->min_subtotal_cents,
                'min_qty' => $rung['tier']->min_qty,
                'min_subtotal' => $rung['tier']->min_subtotal_cents !== null
                    ? MoneyResource::make($rung['tier']->min_subtotal_cents)
                    : null,
                'unit_price' => MoneyResource::make($rung['unit_price_cents']),
                'saving' => MoneyResource::make($rung['saving_cents']),
            ], $ladder)),

            'category' => $this->whenLoaded('category', fn () => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),

            'size_chart' => $this->when(
                $this->sizeChart || $this->category?->sizeChart,
                fn () => [
                    'name' => ($this->sizeChart ?? $this->category->sizeChart)->name,
                    'body' => ($this->sizeChart ?? $this->category->sizeChart)->body,
                ]
            ),

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'path' => $i->url,
                'alt' => $i->alt_text ?? $this->name,
                'color_slug' => $i->color?->slug,
                'is_primary' => (bool) $i->is_primary,
            ])),

            'colors' => $this->whenLoaded('variants', fn () => $this->variants
                ->pluck('color')->filter()->unique('id')->values()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'hex' => $c->hex, 'slug' => $c->slug])
            ),

            'sizes' => $this->whenLoaded('variants', fn () => $this->variants
                ->pluck('size')->filter()->unique('id')
                ->sortBy('position')->values()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug])
            ),

            // Out-of-stock variants are returned, not hidden: a shopper needs to
            // see that their size exists but is unavailable (§2).
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'color_id' => $v->color_id,
                'size_id' => $v->size_id,
                'secondary_size_id' => $v->secondary_size_id,
                'retail_price' => MoneyResource::make($v->retailPriceCents()),
                'available' => $v->availableStock(),
                'in_stock' => $v->isInStock(),
                'low_stock' => $v->isLowStock(),
            ])),

            'in_stock' => $this->whenLoaded('variants', fn () => $this->isInStock()),
            'meta' => [
                'title' => $this->meta_title ?? $this->name,
                'description' => $this->meta_description ?? $this->short_description,
            ],
        ];
    }
}
