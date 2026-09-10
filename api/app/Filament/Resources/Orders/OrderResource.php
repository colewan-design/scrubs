<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Order management (§9).
 *
 * Deliberately view-only: an order is an immutable snapshot of what was agreed,
 * and its status is derived from payment and fulfilment facts. Everything an
 * administrator can change happens through an action that calls OrderService,
 * so every change writes history and no illegal transition is possible. There
 * is no create page for the same reason — orders come from checkout.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /** Orders awaiting payment are the ones needing a human — surface the count. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Order::where('payment_status', Order::PAYMENT_PENDING)
            ->where('fulfillment_status', '!=', Order::FULFILLMENT_CANCELLED)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
