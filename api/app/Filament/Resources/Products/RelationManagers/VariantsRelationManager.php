<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\MoneyInput;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory\InventoryService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Inventory by size and colour (§2, §9).
 *
 * `stock_qty` is deliberately NOT editable here. Every change goes through the
 * Adjust stock action, which writes an inventory_movements row in the same
 * transaction — otherwise a balance can be changed with no record of who
 * changed it or why, and the ledger stops being able to explain itself.
 *
 * `reserved_qty` and `variant_key` are system-owned and read-only for the same
 * reason: the first belongs to in-flight checkouts, the second is derived on
 * save to give the colour/size combination a real uniqueness constraint.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Inventory';

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->variants()->count();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('color_id')
                    ->label('Colour')
                    ->relationship('color', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('size_id')
                    ->label(fn (): string => $this->ownerHasDualSizing() ? 'Top size' : 'Size')
                    ->relationship('size', 'name')
                    ->preload()
                    ->required(),

                // Only meaningful when the product is sized top and bottom
                // independently (open question Q1).
                Select::make('secondary_size_id')
                    ->label('Bottom size')
                    ->relationship('secondarySize', 'name')
                    ->preload()
                    ->visible(fn (): bool => $this->ownerHasDualSizing())
                    ->required(fn (): bool => $this->ownerHasDualSizing()),

                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                MoneyInput::make('retail_price_cents', 'Price override')
                    ->helperText('Leave blank to use the product price.'),

                // Opening stock only. Once the variant exists the figure is
                // owned by the ledger, so the field disappears on edit.
                TextInput::make('stock_qty')
                    ->label('Opening stock')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->visibleOn('create')
                    ->helperText('Recorded as the first movement in the stock ledger.'),

                TextInput::make('low_stock_threshold')
                    ->label('Low stock at')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('Uses the product default'),

                TextInput::make('weight_grams')
                    ->label('Weight (g)')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Used to rate the parcel at checkout.'),

                TextInput::make('length_mm')->label('Length (mm)')->numeric()->minValue(0),
                TextInput::make('width_mm')->label('Width (mm)')->numeric()->minValue(0),
                TextInput::make('height_mm')->label('Height (mm)')->numeric()->minValue(0),

                Toggle::make('is_active')
                    ->label('Available for sale')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->defaultSort('sku')
            ->columns([
                TextColumn::make('color.name')->label('Colour')->searchable()->sortable(),
                TextColumn::make('size.name')->label('Size')->sortable(),
                TextColumn::make('secondarySize.name')
                    ->label('Bottom')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sku')->label('SKU')->searchable()->copyable(),

                TextColumn::make('stock_qty')->label('On hand')->numeric()->sortable(),

                TextColumn::make('reserved_qty')
                    ->label('Reserved')
                    ->numeric()
                    ->placeholder('—')
                    ->tooltip('Held for checkouts that are in flight but not yet paid.'),

                // The number that decides whether the storefront can sell it.
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (ProductVariant $record): int => $record->availableStock())
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match (true) {
                        $record->availableStock() <= 0 => 'danger',
                        $record->isLowStock() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('retail_price_cents')
                    ->label('Price')
                    ->money('CAD', divideBy: 100)
                    ->placeholder('Product price')
                    ->toggleable(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),

                Filter::make('out_of_stock')
                    ->label('Out of stock')
                    ->query(fn (Builder $q): Builder => $q->whereRaw('stock_qty - reserved_qty <= 0')),

                Filter::make('low_stock')
                    ->label('Low stock')
                    ->query(fn (Builder $q): Builder => $q
                        ->whereRaw('stock_qty - reserved_qty > 0')
                        ->whereRaw('stock_qty - reserved_qty <= COALESCE(low_stock_threshold, ?)', [
                            $this->getOwnerRecord()->low_stock_threshold ?? 5,
                        ])),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add variant')
                    // The opening figure is a stock movement like any other, so it
                    // is written to the ledger rather than only to the column.
                    ->after(function (ProductVariant $record): void {
                        if ($record->stock_qty > 0) {
                            InventoryMovement::create([
                                'product_variant_id' => $record->id,
                                'delta' => $record->stock_qty,
                                'balance_after' => $record->stock_qty,
                                'reason' => InventoryMovement::REASON_INITIAL,
                                'user_id' => auth()->id(),
                                'note' => 'Opening stock.',
                            ]);
                        }
                    }),
            ])
            ->recordActions([
                self::adjustStockAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Covers the combinations the per-size fields on the product
                    // form cannot express — one colour priced differently, or a
                    // handful of specific variants.
                    BulkAction::make('setPrice')
                        ->label('Set price')
                        ->icon('heroicon-m-currency-dollar')
                        ->schema([
                            MoneyInput::make('retail_price_cents', 'Price')
                                ->helperText('Leave blank to fall back to the product price.'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $cents = $data['retail_price_cents'] ?? null;

                            ProductVariant::whereIn('id', $records->pluck('id'))
                                ->update(['retail_price_cents' => $cents]);

                            Notification::make()
                                ->title($cents === null ? 'Price override cleared' : 'Price updated')
                                ->body($records->count().' variants repriced.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // No bulk delete: a variant with order history should be
                    // deactivated, not removed, and bulk actions make that too
                    // easy to get wrong in one click.
                ]),
            ])
            ->emptyStateHeading('No variants yet')
            ->emptyStateDescription('Add a size and colour combination to start tracking stock against it.');
    }

    protected static function adjustStockAction(): Action
    {
        return Action::make('adjustStock')
            ->label('Adjust stock')
            ->icon('heroicon-o-arrows-up-down')
            ->modalHeading(fn (ProductVariant $record): string => "Adjust stock — {$record->sku}")
            ->modalSubmitActionLabel('Record adjustment')
            ->schema([
                TextInput::make('delta')
                    ->label('Change')
                    ->numeric()
                    ->required()
                    ->helperText('Positive to add stock, negative to remove. e.g. 24 for a delivery, -2 for damage.'),

                Select::make('reason')
                    ->label('Reason')
                    ->options([
                        InventoryMovement::REASON_PURCHASE => 'Stock received',
                        InventoryMovement::REASON_CORRECTION => 'Count correction',
                        InventoryMovement::REASON_MANUAL => 'Other manual adjustment',
                    ])
                    ->default(InventoryMovement::REASON_PURCHASE)
                    ->required(),

                Textarea::make('note')
                    ->label('Note')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Why the figure changed. Shown in the stock history.'),
            ])
            ->action(function (ProductVariant $record, array $data): void {
                try {
                    $updated = app(InventoryService::class)->adjust(
                        $record,
                        (int) $data['delta'],
                        $data['reason'],
                        $data['note'] ?? null,
                        auth()->user(),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title('Adjustment refused')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Stock updated')
                    ->body("{$record->sku} is now {$updated->stock_qty} on hand.")
                    ->success()
                    ->send();
            });
    }

    protected function ownerHasDualSizing(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Product && (bool) $owner->has_dual_sizing;
    }
}
