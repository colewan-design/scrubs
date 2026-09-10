<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** The list-page stat row — see OrderStats. */
    protected function getHeaderWidgets(): array
    {
        return [
            OrderStats::class,
        ];
    }
}
