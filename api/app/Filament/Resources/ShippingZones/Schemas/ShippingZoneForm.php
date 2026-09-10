<?php

namespace App\Filament\Resources\ShippingZones\Schemas;

use App\Filament\Resources\TaxRates\Schemas\TaxRateForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * A shipping zone groups provinces that are rated the same way (§5).
 *
 * An empty province list is the catch-all — it matches any destination no other
 * zone claims, which is what stops a customer in a province nobody thought
 * about from reaching checkout with no shipping option at all. Position decides
 * which zone wins when more than one matches, so the catch-all belongs last.
 */
class ShippingZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('e.g. "Ontario & Quebec" or "Rest of Canada".'),

                Select::make('provinces')
                    ->label('Provinces')
                    ->multiple()
                    ->options(TaxRateForm::PROVINCES)
                    ->searchable()
                    ->helperText('Leave empty to make this the catch-all zone for anywhere not covered above.'),

                TextInput::make('position')
                    ->label('Priority')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->helperText('Lower numbers are matched first. Keep the catch-all zone highest.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
