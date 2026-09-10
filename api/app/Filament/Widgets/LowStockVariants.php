<?php

namespace App\Filament\Widgets;

use App\Models\ProductVariant;
use App\Support\Settings;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

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

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Low and out of stock';
    }

    public function table(Table $table): Table
    {
        $default = app(Settings::class)->int('inventory.low_stock_threshold', 5);

        return $table
            ->query(function () use ($default): Builder {
                $query = ProductVariant::query()
                    ->with(['product', 'color', 'size'])
                    ->where('is_active', true)
                    ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
                    ->whereRaw(
                        'stock_qty - reserved_qty <= COALESCE(product_variants.low_stock_threshold, '
                        .'(SELECT low_stock_threshold FROM products WHERE products.id = product_variants.product_id), ?)',
                        [$default]
                    );

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
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->url(fn (ProductVariant $r): ?string => $r->product
                        ? "/admin/products/{$r->product->slug}/edit"
                        : null),

                TextColumn::make('displayName')
                    ->label('Variant')
                    ->state(fn (ProductVariant $r): string => $r->displayName()),

                TextColumn::make('sku')->label('SKU')->searchable()->copyable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (ProductVariant $r): int => $r->availableStock())
                    ->badge()
                    ->color(fn (ProductVariant $r): string => $r->availableStock() <= 0 ? 'danger' : 'warning'),

                TextColumn::make('stock_qty')->label('On hand')->numeric(),
                TextColumn::make('reserved_qty')->label('Reserved')->numeric()->placeholder('—'),
            ])
            ->emptyStateHeading('Nothing running low')
            ->emptyStateDescription('Every active variant is above its low-stock level.');
    }
}
