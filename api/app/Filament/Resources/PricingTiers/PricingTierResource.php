<?php

namespace App\Filament\Resources\PricingTiers;

use App\Filament\Resources\PricingTiers\Pages\CreatePricingTier;
use App\Filament\Resources\PricingTiers\Pages\EditPricingTier;
use App\Filament\Resources\PricingTiers\Pages\ListPricingTiers;
use App\Filament\Resources\PricingTiers\Schemas\PricingTierForm;
use App\Filament\Resources\PricingTiers\Tables\PricingTiersTable;
use App\Models\PricingTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingTierResource extends Resource
{
    protected static ?string $model = PricingTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return PricingTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PricingTiersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingTiers::route('/'),
            'create' => CreatePricingTier::route('/create'),
            'edit' => EditPricingTier::route('/{record}/edit'),
        ];
    }
}
