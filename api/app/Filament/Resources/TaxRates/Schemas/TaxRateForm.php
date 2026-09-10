<?php

namespace App\Filament\Resources\TaxRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Canadian sales tax by province (§6).
 *
 * Rates are stored in basis points — 1300 is 13% — so a percentage never has to
 * survive a round trip through a float. The form takes a percentage because
 * that is what a rate table quotes, and converts at the boundary.
 */
class TaxRateForm
{
    /** Provinces and territories, by the two-letter code used on addresses. */
    public const PROVINCES = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('province')
                    ->options(self::PROVINCES)
                    ->searchable()
                    ->required(),

                Select::make('tax_type')
                    ->label('Tax')
                    ->options([
                        'GST' => 'GST — federal',
                        'HST' => 'HST — harmonised',
                        'PST' => 'PST — provincial',
                        'QST' => 'QST — Quebec',
                    ])
                    ->required(),

                TextInput::make('rate_bps')
                    ->label('Rate')
                    ->numeric()
                    ->suffix('%')
                    ->step('0.001')
                    ->minValue(0)
                    ->maxValue(100)
                    ->required()
                    ->helperText('e.g. 13 for 13%.')
                    ->formatStateUsing(
                        fn (?int $state): ?string => $state === null
                            ? null
                            : rtrim(rtrim(number_format($state / 100, 3, '.', ''), '0'), '.')
                    )
                    ->dehydrateStateUsing(
                        fn (mixed $state): int => (int) round(((float) $state) * 100)
                    ),

                Select::make('applies_to')
                    ->label('Applies to')
                    ->options([
                        'goods' => 'Goods',
                        'shipping' => 'Shipping',
                    ])
                    ->default('goods')
                    ->required()
                    ->helperText('Some provinces tax shipping differently from goods.'),

                DatePicker::make('effective_from')
                    ->label('In effect from')
                    ->helperText('Leave blank if it has always applied.'),

                DatePicker::make('effective_to')
                    ->label('In effect until')
                    ->helperText('Set this instead of deleting when a rate changes — historical orders keep their own tax lines either way.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
