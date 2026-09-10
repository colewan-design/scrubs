<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The stat row above the orders table.
 *
 * Part of the list-page pattern ported from the reference admin: every index
 * screen opens with a short row of tiles summarising the table beneath it, so
 * the shape of the workload is legible before anyone reads a row.
 *
 * These are scoped to orders and answer "what do I have to do", which is a
 * different question from the dashboard's "how is the shop doing" — the two
 * deliberately do not repeat each other's figures.
 */
class OrderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $awaitingPayment = Order::where('payment_status', Order::PAYMENT_PENDING)
            ->where('fulfillment_status', '!=', Order::FULFILLMENT_CANCELLED)
            ->count();

        $toFulfil = Order::whereIn('fulfillment_status', [
            Order::FULFILLMENT_PROCESSING,
            Order::FULFILLMENT_READY_FOR_PICKUP,
        ])->count();

        $last7 = Order::where('placed_at', '>=', Carbon::now()->subDays(7))->count();

        $shippedThisWeek = Order::where('fulfillment_status', Order::FULFILLMENT_SHIPPED)
            ->where('updated_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            Stat::make('Awaiting payment', (string) $awaitingPayment)
                ->description($awaitingPayment > 0 ? 'Settle and mark paid' : 'Nothing outstanding')
                ->color($awaitingPayment > 0 ? 'warning' : 'gray'),

            Stat::make('To fulfil', (string) $toFulfil)
                ->description($toFulfil > 0 ? 'Paid, waiting to go out' : 'Nothing waiting')
                ->color($toFulfil > 0 ? 'info' : 'gray'),

            Stat::make('Placed, 7 days', (string) $last7)
                ->description('New orders this week'),

            Stat::make('Shipped, 7 days', (string) $shippedThisWeek)
                ->description('Marked shipped this week')
                ->color($shippedThisWeek > 0 ? 'success' : 'gray'),
        ];
    }
}
