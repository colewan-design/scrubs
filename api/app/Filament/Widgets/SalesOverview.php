<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The figures someone opening the dashboard in the morning needs to act on.
 *
 * Revenue counts paid orders only — counting Pending Payment as revenue would
 * overstate takings for as long as e-Transfer settlement is manual, which is
 * exactly the period this has to be honest about.
 */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    /**
     * Five across at xl, per the dashboard design.
     *
     * Five tiles squeeze to ~126px at 1024, which is why this steps down to
     * three at lg rather than holding five to the bottom of the range — the
     * two-line labels here ("Awaiting payment", "Inventory alerts") are what
     * break first.
     */
    protected int|array|null $columns = [
        'default' => 1,
        'md' => 2,
        'lg' => 3,
        'xl' => 5,
    ];

    protected function getStats(): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subDays(30);
        // The matching window immediately before, so "+12%" compares like with
        // like rather than against an open-ended all-time figure.
        $priorSince = $now->copy()->subDays(60);

        $paidStatuses = [Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED];

        $paid = fn (Carbon $from, Carbon $to) => Order::query()
            ->whereIn('payment_status', $paidStatuses)
            ->whereBetween('placed_at', [$from, $to]);

        $revenueCents = (int) $paid($since, $now)->sum('grand_total_cents');
        $priorRevenueCents = (int) $paid($priorSince, $since)->sum('grand_total_cents');

        $orderCount = $paid($since, $now)->count();
        $priorOrderCount = $paid($priorSince, $since)->count();

        $awaiting = Order::where('payment_status', Order::PAYMENT_PENDING)
            ->where('fulfillment_status', '!=', Order::FULFILLMENT_CANCELLED);
        $awaitingCount = (clone $awaiting)->count();
        $awaitingCents = (int) (clone $awaiting)->sum('grand_total_cents');

        $toFulfil = Order::whereIn('fulfillment_status', [
            Order::FULFILLMENT_PROCESSING,
            Order::FULFILLMENT_READY_FOR_PICKUP,
        ])->count();

        [$outOfStock, $lowStock] = self::inventoryCounts();
        $alerts = $outOfStock + $lowStock;

        return [
            Stat::make('Revenue (30 days)', 'CA$'.number_format($revenueCents / 100, 2))
                ->description(self::delta($revenueCents, $priorRevenueCents))
                ->descriptionIcon(self::deltaIcon($revenueCents, $priorRevenueCents))
                ->color($revenueCents >= $priorRevenueCents ? 'success' : 'danger'),

            Stat::make('Orders (30 days)', (string) $orderCount)
                ->description(self::delta($orderCount, $priorOrderCount))
                ->descriptionIcon(self::deltaIcon($orderCount, $priorOrderCount))
                ->color($orderCount >= $priorOrderCount ? 'success' : 'danger'),

            Stat::make('Awaiting payment', (string) $awaitingCount)
                ->description($awaitingCount > 0
                    ? 'CA$'.number_format($awaitingCents / 100, 2).' outstanding'
                    : 'Nothing outstanding')
                ->color($awaitingCount > 0 ? 'warning' : 'gray'),

            Stat::make('To fulfil', (string) $toFulfil)
                ->description($toFulfil > 0 ? 'Ready for shipment' : 'Nothing waiting')
                ->color($toFulfil > 0 ? 'info' : 'gray'),

            Stat::make('Inventory alerts', (string) $alerts)
                ->description($alerts > 0
                    ? $outOfStock.' out of stock · '.$lowStock.' low stock'
                    : 'Everything in stock')
                ->color($outOfStock > 0 ? 'danger' : ($lowStock > 0 ? 'warning' : 'gray')),
        ];
    }

    /**
     * Out-of-stock and low-stock variant counts.
     *
     * Availability is on-hand minus reserved, so a line held entirely by
     * in-flight checkouts counts as sold out here — which is what the
     * storefront will tell a shopper too.
     *
     * @return array{0:int, 1:int}
     */
    private static function inventoryCounts(): array
    {
        $sellable = ProductVariant::query()
            ->where('is_active', true)
            ->whereRelation('product', 'is_active', true);

        $outOfStock = (clone $sellable)
            ->whereRaw('stock_qty - reserved_qty <= 0')
            ->count();

        $lowStock = (clone $sellable)
            ->whereRaw('stock_qty - reserved_qty > 0')
            ->whereRaw('stock_qty - reserved_qty <= COALESCE(product_variants.low_stock_threshold, (SELECT low_stock_threshold FROM products WHERE products.id = product_variants.product_id))')
            ->count();

        return [$outOfStock, $lowStock];
    }

    /**
     * A percentage is meaningless against a zero baseline — "+100%" off no
     * revenue reads as growth that did not happen — so the first period in
     * says so in words instead.
     */
    private static function delta(int|float $current, int|float $prior): string
    {
        if ($prior <= 0) {
            return $current > 0 ? 'No prior 30 days to compare' : 'Nothing in the last 30 days';
        }

        $pct = (($current - $prior) / $prior) * 100;

        return sprintf('%+.0f%% from previous 30 days', $pct);
    }

    private static function deltaIcon(int|float $current, int|float $prior): ?string
    {
        if ($prior <= 0) {
            return null;
        }

        return $current >= $prior ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }
}
