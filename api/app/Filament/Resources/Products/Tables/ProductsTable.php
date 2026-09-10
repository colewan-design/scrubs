<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\CsvExportAction;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * The product list.
 *
 * Curated down from the generator's output, which put all eighteen columns of
 * the products table on screen — including both meta fields, four timestamps,
 * and prices as raw cents, so a $65.00 scrub set read as "6,500". Wide enough
 * to scroll sideways, and none of it answered the question someone opens this
 * page to ask.
 *
 * What is left is the reference admin's list vocabulary: an identifying column
 * carrying its own secondary line, a couple of facts, money as money, and one
 * status chip. Everything else is toggleable and off by default rather than
 * deleted — it stays one click away in the column manager.
 */
class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // One query for every thumbnail on the page instead of one each.
            ->modifyQueryUsing(fn ($query) => $query->with('images'))
            ->columns([
                // The reference identifies a row by picture first, name second.
                // Primary image, falling back to the first: `is_primary` is not
                // enforced to exist, so a product that never had one set would
                // otherwise show an empty cell while having photos.
                ImageColumn::make('thumbnail')
                    // Table columns have no hiddenLabel(); an empty one is how the
                    // reference leaves the photo column unheaded.
                    ->label('')
                    ->disk('public')
                    ->width(40)
                    ->height(40)
                    ->extraImgAttributes(['class' => 'bsd-thumb'])
                    ->state(fn (Product $r): ?string => ($r->images->firstWhere('is_primary', true)
                        ?? $r->images->first())?->url),

                TextColumn::make('name')
                    ->label('Product')
                    ->description(fn (Product $r): string => $r->base_sku)
                    ->searchable(['name', 'base_sku'])
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('product_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('retail_price_cents')
                    ->label('Retail')
                    ->money('CAD', divideBy: 100)
                    ->sortable(),

                // Sage is the wholesale signal on the storefront; `info` is the
                // slot it is mapped to in the panel, so a wholesale figure
                // reads the same colour in the admin as it does in the shop.
                TextColumn::make('wholesale_base_price_cents')
                    ->label('Wholesale from')
                    ->money('CAD', divideBy: 100)
                    ->badge()
                    ->color('info')
                    ->placeholder('Tier rule')
                    ->sortable(),

                TextColumn::make('variants_count')
                    ->label('Variants')
                    ->counts('variants')
                    ->alignEnd()
                    ->toggleable(),

                // One chip instead of the two boolean ticks it replaces:
                // is_active and published_at combine into a single answer to
                // "can a shopper see this", which is what is actually being
                // asked. Active-but-unpublished is a real and easily missed
                // state, so it gets its own label rather than being folded in.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Product $r): string => match (true) {
                        ! $r->is_active => 'Hidden',
                        $r->published_at === null => 'Draft',
                        $r->published_at->isFuture() => 'Scheduled',
                        default => 'Published',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Scheduled' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('published_at')
                    ->label('Published')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('low_stock_threshold')
                    ->label('Low-stock level')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->preload(),

                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('product_type')
                        ->pluck('product_type', 'product_type')
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label('Visibility')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Hidden only'),

                TrashedFilter::make(),
            ])
            ->headerActions([
                CsvExportAction::make('products', [
                    'Name' => fn (Product $p) => $p->name,
                    'Slug' => fn (Product $p) => $p->slug,
                    'Base SKU' => fn (Product $p) => $p->base_sku,
                    'Category' => fn (Product $p) => $p->category?->name,
                    'Type' => fn (Product $p) => $p->product_type,
                    'Retail price' => fn (Product $p) => CsvExportAction::money($p->retail_price_cents),
                    'Variants' => fn (Product $p) => $p->variants()->count(),
                    'Stock on hand' => fn (Product $p) => (int) $p->variants()->sum('stock_qty'),
                    'Active' => fn (Product $p) => $p->is_active ? 'yes' : 'no',
                    'Published' => fn (Product $p) => $p->published_at?->toDateString(),
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
