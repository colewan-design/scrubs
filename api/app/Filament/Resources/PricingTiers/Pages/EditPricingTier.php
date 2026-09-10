<?php

namespace App\Filament\Resources\PricingTiers\Pages;

use App\Filament\Resources\PricingTiers\PricingTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingTier extends EditRecord
{
    protected static string $resource = PricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
