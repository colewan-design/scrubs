<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Return stock held by card checkouts nobody finished (§2 inventory, §7).
 *
 * WHY THIS EXISTS NOW AND DID NOT BEFORE
 *
 * Placing an order reserves its stock, and until cards were connected every
 * order was settled by a human — so every reservation was resolved by a human
 * too. A card step introduces the one thing that was previously impossible: an
 * order the customer simply walks away from, halfway through paying, holding
 * the last three units of a size nobody else can now buy. Without this command
 * `orders.reservation_ttl_minutes` is a setting that does nothing.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH
 *
 * e-Transfer and manual orders. Those are *supposed* to sit in Pending Payment
 * for days while a customer gets to their banking app — sweeping them would
 * cancel legitimate orders on a timer. Only gateway payments expire, because
 * only a gateway payment tells us the customer was at a card form and left.
 *
 * ON THE RACE WITH A LATE PAYMENT
 *
 * A customer could pay in the same second this releases their order. That is
 * survivable rather than prevented: markPaid() refuses a cancelled order, and
 * StripeWebhookController records the money and flags it for refund instead of
 * shipping goods against stock that has already gone back on the shelf. The
 * alternative — verifying every intent against Stripe before every sweep —
 * buys a smaller window at the cost of a network call per abandoned basket.
 */
class ReleaseAbandonedOrders extends Command
{
    protected $signature = 'orders:release-abandoned
                            {--dry-run : List what would be released without touching anything}';

    protected $description = 'Cancel unpaid card orders past the reservation window and return their stock';

    public function handle(Settings $settings, OrderService $orders): int
    {
        $minutes = max(1, $settings->reservationTtlMinutes());
        $cutoff = now()->subMinutes($minutes);
        $dryRun = (bool) $this->option('dry-run');

        $stale = Order::query()
            ->where('payment_status', Order::PAYMENT_PENDING)
            ->where('fulfillment_status', Order::FULFILLMENT_UNFULFILLED)
            ->where('placed_at', '<', $cutoff)
            ->whereHas('payments', fn ($q) => $q
                ->where('provider', Payment::PROVIDER_STRIPE)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED]))
            ->with('items')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("Nothing to release (reservation window: {$minutes} minutes).");

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d order(s) placed before %s.',
            $dryRun ? 'Would release' : 'Releasing',
            $stale->count(),
            $cutoff->toDateTimeString(),
        ));

        $released = 0;

        foreach ($stale as $order) {
            // Re-read immediately before acting. The list above may be seconds
            // old, and a webhook may have settled this order in between.
            $order->refresh();

            if (! $order->awaitingPayment()) {
                continue;
            }

            if ($dryRun) {
                $this->line("  would release {$order->order_number} ({$order->items->count()} line(s))");
                $released++;

                continue;
            }

            try {
                $orders->cancel($order, 'Payment not completed within the reservation window.');
                $released++;
                $this->line("  released {$order->order_number}");
            } catch (Throwable $e) {
                // One stuck order must not stop the rest being returned to sale.
                $this->error("  {$order->order_number}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(($dryRun ? 'Would release ' : 'Released ').$released.' order(s).');

        return self::SUCCESS;
    }
}
