<?php

namespace App\Filament\Resources\PricingTiers\Pages;

use App\Filament\Resources\PricingTiers\PricingTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingTiers extends ListRecords
{
    protected static string $resource = PricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
