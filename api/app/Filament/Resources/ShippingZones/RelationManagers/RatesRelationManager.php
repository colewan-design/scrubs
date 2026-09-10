<?php

namespace App\Filament\Resources\ShippingZones\RelationManagers;

use App\Filament\Support\MoneyInput;
use App\Models\ShippingRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The rates a zone offers at checkout (§5).
 *
 * A rate can be bracketed by parcel weight, by order subtotal, or both. Leaving
 * a bound empty means unbounded in that direction, so a single rate with no
 * brackets is a flat fee for the whole zone.
 *
 * These are the table rates — the fallback that prices shipping when live
 * carrier rating is unavailable. They are what will be used at launch unless
 * Stallion Express credentials arrive first, so they matter more than "fallback"
 * suggests.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Rates';

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->rates()->count();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Shown to the customer, e.g. "Standard" or "Expedited".'),

                    MoneyInput::make('rate_cents', 'Price')
                        ->required()
                        ->default(0),

                    Toggle::make('is_free')
                        ->label('Always free')
                        ->helperText('Overrides the price. The free-shipping threshold in Store settings applies separately.'),

                    Toggle::make('is_active')->label('Active')->default(true),
                ]),

                Section::make('Applies when')
                    ->description('Leave a bound empty for "no limit". A rate with no bounds applies to every order in the zone.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('min_weight_grams')->label('Min weight (g)')->numeric()->minValue(0),
                        TextInput::make('max_weight_grams')->label('Max weight (g)')->numeric()->minValue(0),

                        MoneyInput::make('min_subtotal_cents', 'Min order subtotal'),
                        MoneyInput::make('max_subtotal_cents', 'Max order subtotal'),
                    ]),

                Section::make('Delivery estimate')
                    ->columns(2)
                    ->schema([
                        TextInput::make('delivery_days_min')->label('From (business days)')->numeric()->minValue(0),
                        TextInput::make('delivery_days_max')->label('To (business days)')->numeric()->minValue(0),

                        TextInput::make('position')
                            ->label('Sort order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Order the options appear in at checkout.'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('name')->searchable(),

                TextColumn::make('rate_cents')
                    ->label('Price')
                    ->money('CAD', divideBy: 100)
                    ->description(fn (ShippingRate $record): ?string => $record->is_free ? 'Always free' : null),

                TextColumn::make('weight_bracket')
                    ->label('Weight')
                    ->state(fn (ShippingRate $r): string => self::bracket($r->min_weight_grams, $r->max_weight_grams, 'g'))
                    ->placeholder('Any'),

                TextColumn::make('subtotal_bracket')
                    ->label('Subtotal')
                    ->state(fn (ShippingRate $r): string => self::bracket(
                        $r->min_subtotal_cents === null ? null : $r->min_subtotal_cents / 100,
                        $r->max_subtotal_cents === null ? null : $r->max_subtotal_cents / 100,
                        '',
                        '$',
                    ))
                    ->placeholder('Any'),

                TextColumn::make('delivery')
                    ->label('Delivery')
                    ->state(fn (ShippingRate $r): string => match (true) {
                        $r->delivery_days_min && $r->delivery_days_max => "{$r->delivery_days_min}–{$r->delivery_days_max} days",
                        (bool) $r->delivery_days_max => "Up to {$r->delivery_days_max} days",
                        default => '',
                    })
                    ->placeholder('—'),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add rate'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No rates in this zone')
            ->emptyStateDescription('Until a rate exists, customers in this zone see no shipping option at checkout.');
    }

    protected static function bracket(?float $min, ?float $max, string $suffix = '', string $prefix = ''): string
    {
        $fmt = fn (float $v): string => $prefix.rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.').$suffix;

        return match (true) {
            $min !== null && $max !== null => $fmt($min).' – '.$fmt($max),
            $min !== null => $fmt($min).'+',
            $max !== null => 'Up to '.$fmt($max),
            default => '',
        };
    }
}
