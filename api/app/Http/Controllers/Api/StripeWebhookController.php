<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Event;

/**
 * Stripe's word on what actually happened (§4, §7).
 *
 * THIS — NOT THE BROWSER — IS WHAT MARKS AN ORDER PAID.
 *
 * The storefront's `confirmPayment()` resolving is a hint, nothing more. The
 * customer can close the tab mid-redirect, lose signal, or be on a payment
 * method that settles minutes later; and anything the browser tells us is
 * ultimately a claim from an untrusted client. Every path that commits stock
 * and sends a receipt starts here, with a signed event from Stripe.
 *
 * THREE PROPERTIES THIS HANDLER MUST KEEP
 *
 *  1. Authenticated. An unverified POST to this URL is an anonymous request to
 *     mark an order paid. No signature, no processing — 400, every time.
 *
 *  2. Idempotent. Stripe retries on any non-2xx and can deliver the same event
 *     more than once regardless. markPaid() returns early on an already-paid
 *     order, and refunds are deduplicated on the Stripe refund id.
 *
 *  3. Forgiving of order. `charge.refunded` can arrive before the
 *     `payment_intent.succeeded` it belongs to. Unknown or not-yet-ready
 *     references are answered 200 so Stripe stops retrying, and logged so a
 *     human can find them — never 500, which would have Stripe hammering a
 *     broken endpoint for days.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeGateway $stripe,
        protected OrderService $orders,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->verifyWebhook(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (PaymentException $e) {
            Log::warning('Stripe webhook rejected.', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handleSucceeded($event),
            'payment_intent.payment_failed' => $this->handleFailed($event),
            'charge.refunded' => $this->handleRefunded($event),
            // Everything else Stripe is configured to send is acknowledged and
            // ignored. Returning 200 for an event we do not act on is correct;
            // returning an error would make Stripe retry it forever.
            default => null,
        };

        return response()->json(['received' => true]);
    }

    // ------------------------------------------------------------------ events

    protected function handleSucceeded(Event $event): void
    {
        $intent = $event->data->object;
        $payment = $this->paymentFor($intent->id);

        if (! $payment) {
            Log::warning('Stripe webhook: no payment for intent.', ['intent' => $intent->id]);

            return;
        }

        $this->recordCardDetails($payment, $intent);

        $order = $payment->order;

        // An intent that succeeded for less than the order total is not payment
        // of that order. Refusing here is what stops a manipulated or stale
        // intent from releasing goods.
        if ((int) $intent->amount_received < $order->grand_total_cents) {
            Log::error('Stripe webhook: underpayment refused.', [
                'order' => $order->order_number,
                'received' => $intent->amount_received,
                'expected' => $order->grand_total_cents,
            ]);

            $this->flag($order, sprintf(
                'Stripe reported $%s against a total of $%s. Payment not applied — needs review.',
                number_format(((int) $intent->amount_received) / 100, 2),
                number_format($order->grand_total_cents / 100, 2),
            ));

            return;
        }

        try {
            $this->orders->markPaid($order, $payment);
        } catch (RuntimeException $e) {
            // The order was cancelled while the customer was paying — the
            // reservation sweeper released it, or an administrator did. The
            // money is real and now has to go back, so this is escalated rather
            // than swallowed: nothing is more expensive than a silently kept
            // payment for goods that were never allocated.
            Log::error('Stripe webhook: payment landed on an unusable order.', [
                'order' => $order->order_number,
                'intent' => $intent->id,
                'reason' => $e->getMessage(),
            ]);

            $payment->forceFill([
                'status' => Payment::STATUS_SUCCEEDED,
                'paid_at' => now(),
            ])->save();

            $this->flag($order, 'Payment received AFTER cancellation ('.$intent->id.'). Refund required.');
        }
    }

    protected function handleFailed(Event $event): void
    {
        $intent = $event->data->object;
        $payment = $this->paymentFor($intent->id);

        if (! $payment) {
            return;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_FAILED,
            'raw_response' => ['last_payment_error' => $intent->last_payment_error ?? null],
        ])->save();

        // The order is deliberately left in Pending Payment holding its stock.
        // A declined card is usually followed by a second attempt on another
        // card a minute later, and releasing the basket underneath the customer
        // would turn a retry into an out-of-stock message. The sweeper collects
        // it if no retry comes.
        $this->flag(
            $payment->order,
            'Card payment failed: '.($intent->last_payment_error->message ?? 'declined').'.',
        );
    }

    /**
     * A refund issued from the Stripe dashboard rather than from admin.
     *
     * Refunds started on our side already wrote their ledger row before Stripe
     * was called, so this deduplicates on the Stripe refund id and records only
     * what it has never seen.
     */
    protected function handleRefunded(Event $event): void
    {
        $charge = $event->data->object;
        $payment = $this->paymentFor($charge->payment_intent ?? null);

        if (! $payment) {
            return;
        }

        $order = $payment->order;

        foreach ($charge->refunds->data ?? [] as $refund) {
            $known = $order->refunds()->where('provider_reference', $refund->id)->exists();

            if ($known) {
                continue;
            }

            try {
                $this->orders->refund(
                    order: $order,
                    amountCents: (int) $refund->amount,
                    reason: 'Refunded from the Stripe dashboard.',
                    // Stock is NOT returned automatically. Stripe cannot know
                    // whether the goods came back, and inventing stock is worse
                    // than making an administrator say so.
                    restock: false,
                    providerReference: (string) $refund->id,
                    throughGateway: false,
                );
            } catch (RuntimeException $e) {
                Log::error('Stripe webhook: could not record dashboard refund.', [
                    'order' => $order->order_number,
                    'refund' => $refund->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    // --------------------------------------------------------------- internals

    protected function paymentFor(?string $intentId): ?Payment
    {
        if (! $intentId) {
            return null;
        }

        return Payment::with('order')
            ->where('provider', Payment::PROVIDER_STRIPE)
            ->where('provider_reference', $intentId)
            ->first();
    }

    /**
     * Last four and brand only — §12 again. Everything else Stripe knows about
     * the card stays at Stripe, where it is their compliance problem and not
     * BulkScrubs Direct's.
     */
    protected function recordCardDetails(Payment $payment, object $intent): void
    {
        $card = $intent->latest_charge ?? null;
        $details = is_object($card) ? ($card->payment_method_details->card ?? null) : null;

        $payment->forceFill(array_filter([
            'card_brand' => $details->brand ?? null,
            'card_last_four' => $details->last4 ?? null,
            'method' => $intent->payment_method_types[0] ?? 'card',
        ]))->save();
    }

    /**
     * Put something in front of an administrator without inventing a status.
     *
     * Internal by definition: every note that reaches here is the provider
     * talking to the shop about money that went wrong, and none of it is
     * phrased for the person who was trying to buy some scrubs.
     */
    protected function flag(Order $order, string $note): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'note' => $note,
            'is_internal' => true,
            'notified_customer' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
