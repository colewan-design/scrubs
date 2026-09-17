<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\ProductVariant;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * What needs reordering (§2, §9).
 *
 * The threshold is per variant, falling back to the product's, then to the
 * store default — the same precedence ProductVariant::isLowStock() uses, so the
 * dashboard and the product page never disagree about what "low" means.
 *
 * Sold-out lines are included and sort first: they are the ones actively
 * costing sales.
 */
class LowStockVariants extends TableWidget
{
    protected static ?int $sort = 1;

    // Full width: eight data columns truncate at anything narrower.
    protected int|string|array $columnSpan = 'full';

    /**
     * Tabs are hand-rolled rather than borrowed from Resources\Concerns\HasTabs:
     * that trait expects a ListRecords page to render its tab strip, and a
     * widget has no such slot. One Livewire property and a filtered query is
     * less machinery than bending the trait into a widget.
     */
    #[Url(as: 'stock', keep: false)]
    public string $statusTab = 'all';

    protected string $view = 'filament.widgets.low-stock-variants';

    public function setStatusTab(string $tab): void
    {
        $this->statusTab = $tab;
        $this->resetTable();
    }

    public function getTableHeading(): string
    {
        return 'Inventory alerts';
    }

    public function getTableDescription(): string
    {
        return 'Products that are low in stock or out of stock.';
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('viewAllProducts')
                ->label('View all products')
                ->icon('heroicon-m-arrow-right')
                ->iconPosition('after')
                ->link()
                ->url(ProductResource::getUrl('index')),
        ];
    }

    /**
     * Counts for the tab badges. One grouped pass rather than three counts —
     * the tab strip re-renders on every table interaction.
     *
     * @return array{all:int, out:int, low:int}
     */
    public function getTabCounts(): array
    {
        $rows = $this->baseQuery()
            ->selectRaw('CASE WHEN stock_qty - reserved_qty <= 0 THEN 1 ELSE 0 END AS is_out, COUNT(*) AS c')
            ->groupBy('is_out')
            ->pluck('c', 'is_out');

        $out = (int) ($rows[1] ?? 0);
        $low = (int) ($rows[0] ?? 0);

        return ['all' => $out + $low, 'out' => $out, 'low' => $low];
    }

    /** Everything at or below its threshold, sold-out included. */
    private function baseQuery(): Builder
    {
        $default = app(Settings::class)->int('inventory.low_stock_threshold', 5);

        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->whereRaw(
                'stock_qty - reserved_qty <= COALESCE(product_variants.low_stock_threshold, '
                .'(SELECT low_stock_threshold FROM products WHERE products.id = product_variants.product_id), ?)',
                [$default]
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = $this->baseQuery()->with(['product.images', 'color', 'size']);

                match ($this->statusTab) {
                    'out' => $query->whereRaw('stock_qty - reserved_qty <= 0'),
                    'low' => $query->whereRaw('stock_qty - reserved_qty > 0'),
                    default => null,
                };

                // Ordered on the widget's own query, not through a
                // defaultSort() closure. That closure returned the raw query
                // builder, Filament assigned it over the Eloquent one and then
                // asked it for a model to hang its tiebreaker key sort on —
                // CanSortRecords:123, "getQualifiedKeyName() on null", a 500 on
                // every dashboard load. Sorting here keeps "worst first" and
                // leaves the key sort to do what it is for: break ties so
                // pagination is stable.
                $query->orderByRaw('stock_qty - reserved_qty ASC');

                return $query;
            })
            ->paginated([10, 25])
            ->searchPlaceholder('Search products, variants, or SKU...')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (ProductVariant $r): ?string => $r->product?->images
                        ->firstWhere('color_id', $r->color_id)?->url
                        ?? $r->product?->images->first()?->url)
                    ->height(36)
                    ->width(36)
                    ->extraImgAttributes(['class' => 'rounded-md object-cover']),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->url(fn (ProductVariant $r): ?string => $r->product
                        ? ProductResource::getUrl('edit', ['record' => $r->product])
                        : null),

                TextColumn::make('displayName')
                    ->label('Variant')
                    ->state(fn (ProductVariant $r): string => $r->displayName()),

                TextColumn::make('sku')->label('SKU')->searchable()->copyable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (ProductVariant $r): int => $r->availableStock())
                    ->numeric(),

                TextColumn::make('stock_qty')->label('On hand')->numeric(),
                TextColumn::make('reserved_qty')->label('Reserved')->numeric()->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ProductVariant $r): string => $r->availableStock() <= 0 ? 'Out of stock' : 'Low stock')
                    ->icon(fn (ProductVariant $r): string => $r->availableStock() <= 0
                        ? 'heroicon-m-x-circle'
                        : 'heroicon-m-exclamation-triangle')
                    ->color(fn (ProductVariant $r): string => $r->availableStock() <= 0 ? 'danger' : 'warning'),
            ])
            ->emptyStateHeading(match ($this->statusTab) {
                'out' => 'Nothing out of stock',
                'low' => 'Nothing running low',
                default => 'Nothing needs attention',
            })
            ->emptyStateDescription('Every active variant is above its low-stock level.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
