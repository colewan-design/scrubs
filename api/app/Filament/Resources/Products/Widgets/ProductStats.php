<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The stat row above the products table — see OrderStats for the pattern.
 *
 * Catalogue health rather than sales: what is live, what is hidden, and how
 * much of the sellable range is currently unsellable. "Out of stock" counts
 * variants, not products, because a product with one sold-out colour is not
 * out of stock and reporting it as such sends someone reordering the wrong SKU.
 */
class ProductStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $published = Product::where('is_active', true)->whereNotNull('published_at')->count();
        $drafts = Product::where(fn ($q) => $q->where('is_active', false)->orWhereNull('published_at'))->count();

        $variants = ProductVariant::where('is_active', true)->count();

        $outOfStock = ProductVariant::where('is_active', true)
            ->whereRaw('stock_qty - reserved_qty <= 0')
            ->count();

        return [
            Stat::make('Published', (string) $published)
                ->description('Live on the storefront')
                ->color('success'),

            Stat::make('Draft or hidden', (string) $drafts)
                ->description($drafts > 0 ? 'Not visible to shoppers' : 'Everything is live')
                ->color($drafts > 0 ? 'warning' : 'gray'),

            Stat::make('Active variants', (string) $variants)
                ->description('Sellable colour and size combinations'),

            Stat::make('Out of stock', (string) $outOfStock)
                ->description($outOfStock > 0 ? 'Active variants unsellable' : 'Everything sellable')
                ->color($outOfStock > 0 ? 'danger' : 'gray'),
        ];
    }
}
