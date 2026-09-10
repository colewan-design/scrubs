<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    /**
     * Catalogue listing with filtering and sorting.
     *
     * Anyone may browse without an account (§3); retail pricing is public,
     * wholesale pricing is withheld by the resource for guests.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'colors' => ['nullable', 'array'],
            'colors.*' => ['string'],
            'sizes' => ['nullable', 'array'],
            'sizes.*' => ['string'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Product::query()
            ->published()
            // tierPrices feeds PricingService::ladderFor() in the resource.
            ->with(['category', 'images', 'tierPrices', 'variants.color', 'variants.size']);

        if ($slug = $validated['category'] ?? null) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $slug));
        }

        if ($colors = $validated['colors'] ?? null) {
            $query->whereHas('variants.color', fn ($q) => $q->whereIn('slug', $colors));
        }

        if ($sizes = $validated['sizes'] ?? null) {
            $query->whereHas('variants.size', fn ($q) => $q->whereIn('slug', $sizes));
        }

        if (isset($validated['min_price'])) {
            $query->where('retail_price_cents', '>=', $validated['min_price']);
        }

        if (isset($validated['max_price'])) {
            $query->where('retail_price_cents', '<=', $validated['max_price']);
        }

        if ($validated['in_stock'] ?? false) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_active', true)
                ->whereColumn('stock_qty', '>', 'reserved_qty'));
        }

        if ($search = $validated['search'] ?? null) {
            // MySQL full-text is sufficient at this catalogue size; a dedicated
            // search service would be premature.
            $query->whereFullText(['name', 'short_description'], $search);
        }

        match ($validated['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('retail_price_cents'),
            'price_desc' => $query->orderByDesc('retail_price_cents'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        return ProductCardResource::collection(
            $query->paginate($validated['per_page'] ?? 24)->withQueryString()
        );
    }

    public function show(Request $request, Product $product): ProductDetailResource
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'category.sizeChart',
            'sizeChart',
            'images.color',
            // Read by PricingService::ladderFor() via the resource.
            'tierPrices',
            'variants' => fn ($q) => $q->where('is_active', true),
            'variants.color',
            'variants.size',
            'variants.secondarySize',
        ]);

        // Sizes must present in garment order, never alphabetically.
        $product->setRelation(
            'variants',
            $product->variants->sortBy(fn ($v) => $v->size?->position ?? 0)->values()
        );

        return new ProductDetailResource($product);
    }
}
