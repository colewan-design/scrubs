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

        try {
            $this->settle($payment, $intent);
        } finally {
            // Last, whatever happened above, and never in its way: this asks
            // Stripe a question, and the answer to "which card?" must not be
            // able to delay or undo the answer to "is this order paid?".
            $this->recordCardDetails($payment, $intent);
        }
    }

    /** Decide what a reported payment means for the order it was made against. */
    protected function settle(Payment $payment, object $intent): void
    {
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
            // Already dealt with. A redelivered event must not raise the alarm
            // a second time — or mark as held a payment that has since been
            // sent back.
            if (in_array($payment->status, [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED], true)) {
                return;
            }

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

            $this->flag($order, 'Payment received AFTER cancellation ('.$intent->id.'). Refund required — '
                .'use Refund on this order.');
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
     * The event says only that the charge has been refunded — Stripe stopped
     * sending the refunds themselves with it in API version 2022-11-15 — so the
     * gateway is asked which refunds exist that this application did not issue,
     * and any the ledger has never seen are written down. Refunds started in
     * admin never come back from that question: the request that issued them
     * records them, and recording them here as well would count the money
     * twice.
     */
    protected function handleRefunded(Event $event): void
    {
        $charge = $event->data->object;
        $payment = $this->paymentFor($charge->payment_intent ?? null);

        if (! $payment) {
            return;
        }

        $order = $payment->order;

        try {
            $refunds = $this->stripe->refundsMadeElsewhere($payment);
        } catch (PaymentException $e) {
            // Still a 200: Stripe would only send the same event again, and an
            // endpoint that keeps failing gets switched off — taking payment
            // confirmations with it. But money has moved that the ledger does
            // not show, so a person is told where to look.
            Log::error('Stripe webhook: could not read the refunds on a charge.', [
                'order' => $order->order_number,
                'charge' => $charge->id ?? null,
                'reason' => $e->getMessage(),
            ]);

            $this->flag($order, 'Stripe reported a refund on this payment, but its details could not be '
                .'read. Check the payment in the Stripe dashboard and record the refund here by hand.');

            return;
        }

        foreach ($refunds as $refund) {
            // A refund Stripe has refused or cancelled returned nothing.
            if (! $refund->succeeded()) {
                continue;
            }

            $known = $order->refunds()->where('provider_reference', $refund->reference)->exists();

            if ($known) {
                continue;
            }

            try {
                $this->orders->refund(
                    order: $order,
                    amountCents: $refund->amountCents,
                    reason: 'Refunded from the Stripe dashboard.',
                    // Stock is NOT returned automatically. Stripe cannot know
                    // whether the goods came back, and inventing stock is worse
                    // than making an administrator say so.
                    restock: false,
                    providerReference: $refund->reference,
                    throughGateway: false,
                );
            } catch (RuntimeException $e) {
                Log::error('Stripe webhook: could not record dashboard refund.', [
                    'order' => $order->order_number,
                    'refund' => $refund->reference,
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
        try {
            $charge = $intent->latest_charge ?? null;

            // The event carries the charge's id, not the charge, so the card
            // has to be asked for. (API versions before 2022-11-15 sent it
            // whole, and that is still read if it ever turns up.) Asked once:
            // a redelivered event finds the answer already written down.
            $card = match (true) {
                is_object($charge) => [
                    'brand' => $charge->payment_method_details->card->brand ?? null,
                    'last4' => $charge->payment_method_details->card->last4 ?? null,
                ],
                (bool) $payment->card_last_four => null,
                default => $this->stripe->cardDetails($intent->id),
            };

            $payment->forceFill(array_filter([
                'card_brand' => $card['brand'] ?? null,
                'card_last_four' => $card['last4'] ?? null,
                'method' => $intent->payment_method_types[0] ?? 'card',
            ]))->save();
        } catch (\Throwable $e) {
            // Called on the way out of a payment that has already been dealt
            // with. Nothing that goes wrong here is worth a failed webhook.
            Log::warning('Stripe webhook: could not record card details.', [
                'intent' => $intent->id ?? null,
                'reason' => $e->getMessage(),
            ]);
        }
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
