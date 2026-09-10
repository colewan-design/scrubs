<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Basic sales reporting (§9).
 *
 * Deliberately small: the figures someone opening the dashboard in the
 * morning actually needs to act on. Revenue counts paid orders only — counting
 * Pending Payment as revenue would overstate takings for as long as e-Transfer
 * settlement is manual, which is exactly the period this has to be honest
 * about.
 */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    /**
     * Four across, matching the list-page stat rows.
     *
     * Five stats in the default three-column grid left a hole in the second
     * row — two tiles and an empty cell, which reads as something failing to
     * load rather than as a layout. Five across fixed the hole but squeezed
     * each tile to 126px at 1024, where "Active variants unsellable" no longer
     * fits on two lines. Four divides cleanly at both steps instead.
     */
    protected int|array|null $columns = [
        'default' => 1,
        'md' => 2,
        'xl' => 4,
    ];

    protected function getStats(): array
    {
        $since = Carbon::now()->subDays(30);

        $paid = Order::query()
            ->whereIn('payment_status', [
                Order::PAYMENT_PAID,
                Order::PAYMENT_PARTIALLY_REFUNDED,
            ])
            ->where('placed_at', '>=', $since);

        $revenueCents = (int) (clone $paid)->sum('grand_total_cents');
        $paidCount = (clone $paid)->count();

        $awaitingPayment = Order::where('payment_status', Order::PAYMENT_PENDING)
            ->where('fulfillment_status', '!=', Order::FULFILLMENT_CANCELLED)
            ->count();

        $toFulfil = Order::whereIn('fulfillment_status', [
            Order::FULFILLMENT_PROCESSING,
            Order::FULFILLMENT_READY_FOR_PICKUP,
        ])->count();

        return [
            Stat::make('Revenue, 30 days', 'CA$'.number_format($revenueCents / 100, 2))
                ->description($paidCount.' paid '.str('order')->plural($paidCount))
                ->color('success'),

            Stat::make('Average order', $paidCount > 0
                ? 'CA$'.number_format(($revenueCents / $paidCount) / 100, 2)
                : '—')
                ->description('Across the same 30 days'),

            Stat::make('Awaiting payment', (string) $awaitingPayment)
                ->description($awaitingPayment > 0 ? 'Settle and mark paid' : 'Nothing outstanding')
                ->color($awaitingPayment > 0 ? 'warning' : 'gray'),

            Stat::make('To fulfil', (string) $toFulfil)
                ->description($toFulfil > 0 ? 'Paid and waiting to go out' : 'Nothing waiting')
                ->color($toFulfil > 0 ? 'info' : 'gray'),

            // No "out of stock" tile. The table directly beneath this row is
            // the itemised version of that same count, and the products list
            // carries it as a tile of its own — a number repeated three times
            // on two screens is one of them earning its place, not three.
        ];
    }
}
