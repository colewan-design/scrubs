<?php

namespace App\Http\Resources;

use App\Services\Pricing\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact shape used in grids and carousels.
 *
 * Wholesale figures are NEVER included for a guest (§3). What a logged-out
 * visitor receives is `wholesale_locked: true` and nothing more — the price
 * itself does not cross the wire, so it cannot leak through the network tab.
 *
 * The figure shown to a member comes from PricingService, NOT from
 * `wholesale_base_price_cents`. That column is a reference figure (the brief's
 * "~$45/set") which nothing in the pricing engine charges; advertising it here
 * meant the grid quoted a price the cart would not honour.
 */
class ProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $image = $this->primaryImage();

        // Cheapest rung this product actually reaches. Empty for a guest.
        $ladder = $user !== null
            ? app(PricingService::class)->ladderFor($this->resource, $user)
            : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->whenLoaded('category', fn () => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'short_description' => $this->short_description,
            // The lowest price a shopper could actually pay, not the product's
            // default. With a plus-size upcharge the two differ, and the grid
            // must not quote a number the cart will not honour.
            'retail_price' => $this->when(
                $this->relationLoaded('variants'),
                fn () => MoneyResource::make($this->retailPriceRangeCents()[0]),
                fn () => MoneyResource::make($this->retail_price_cents),
            ),
            // Tells the card to render "from $65" rather than a flat figure.
            'retail_price_varies' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->retailPriceVaries(),
                false,
            ),
            'wholesale_locked' => $user === null,
            // The ENTRY rung, not the deepest one. The site's own promise is
            // "wholesale on orders over $200" — quoting the $1,500 tier's price
            // here would advertise a number most baskets never reach.
            'wholesale_from' => $this->when(
                $ladder !== [],
                fn () => MoneyResource::make($ladder[0]['unit_price_cents'])
            ),
            'image' => $image ? [
                'path' => $image->url,
                'alt' => $image->alt_text ?? $this->name,
            ] : null,
            'colors' => $this->whenLoaded('variants', fn () => $this->variants
                ->pluck('color')
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn ($c) => ['name' => $c->name, 'hex' => $c->hex, 'slug' => $c->slug])
            ),
            'in_stock' => $this->whenLoaded('variants', fn () => $this->isInStock()),
            'is_featured' => (bool) $this->is_featured,
        ];
    }
}
