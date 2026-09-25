<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PayPal Checkout against our own orders (§4, §7).
 *
 * THE FLOW, AND WHY IT IS THIS WAY ROUND
 *
 * The order is placed in our database FIRST, as Pending Payment with its stock
 * reserved, and only then handed to PayPal. That ordering is what makes the
 * integration safe:
 *
 *  - The amount PayPal is asked for is read off a persisted order that was
 *    priced server-side. The browser never names a figure, so a tampered client
 *    cannot pay $1 for $400 of scrubs.
 *  - An abandoned PayPal window leaves a Pending Payment order the admin can
 *    see and cancel — identical to how e-Transfer already behaves — rather than
 *    a completed payment with no order attached to it.
 *  - Stock is held for the duration of the PayPal window, so the last box of
 *    smalls cannot be sold twice while one customer is on paypal.com.
 *
 * FOUR RULES THIS CLASS MUST NEVER BREAK
 *
 *  1. A capture is only ever accepted after its amount and currency have been
 *     checked against the order's own grand total. PayPal reporting COMPLETED is
 *     not by itself evidence that the right amount was paid.
 *
 *  2. markPaid() is reached through OrderService and nowhere else, so committing
 *     stock, writing history and notifying the customer keep happening exactly
 *     once per order, whichever path confirmed the money.
 *
 *  3. Capture is idempotent. The browser callback and the webhook routinely
 *     both arrive; the second one must be a no-op, not a second charge or a
 *     second stock decrement.
 *
 *  4. Nothing is captured for an order that is not still awaiting payment.
 *     A cancelled order must not become payable because a stale PayPal window
 *     was left open.
 */
class PayPalService
{
    public function __construct(
        protected PayPalClient $client,
        protected OrderService $orders,
        protected Settings $settings,
    ) {}

    /**
     * Whether checkout should offer PayPal at all.
     *
     * Two gates, both required: credentials in the environment, and the switch
     * in Store settings. The switch exists so a configured live key cannot start
     * taking real money the moment it is deployed — the same pattern the Stallion
     * rates and the order emails already use.
     */
    public function enabled(): bool
    {
        return $this->client->configured() && $this->settings->bool('payments.paypal_enabled');
    }

    /** What the storefront needs to render the buttons. Null when unavailable. */
    public function publicConfig(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return [
            'client_id' => $this->client->clientId(),
            'mode' => $this->client->mode(),
            'currency' => 'CAD',
        ];
    }

    /**
     * Create the PayPal order for one of ours and return PayPal's id.
     *
     * Safe to call more than once for the same order: a customer who closes the
     * PayPal window and clicks the button again gets a fresh PayPal order
     * against the same order of ours, rather than a duplicate order here.
     *
     * @throws RuntimeException when PayPal cannot be reached or refuses.
     */
    public function createOrderFor(Order $order): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('PayPal is not available at the moment.');
        }

        $this->assertPayable($order);

        $response = $this->client->createOrder(
            $this->orderPayload($order),
            // Scoped to this attempt, not to the order: two deliberate attempts
            // on the same order are two different PayPal orders, and reusing the
            // id would hand back the abandoned first one.
            'bsd-order-'.$order->id.'-'.Str::random(12),
        );

        $paypalOrderId = $response?->successful() ? $response->json('id') : null;

        if (! is_string($paypalOrderId) || $paypalOrderId === '') {
            throw new RuntimeException(
                'We could not reach PayPal just now. Please try again, or choose another payment method.'
            );
        }

        $payment = $this->paymentFor($order);

        $payment->update([
            'provider' => Payment::PROVIDER_PAYPAL,
            'method' => Payment::PROVIDER_PAYPAL,
            // Until there is a capture to point at, the PayPal order id is the
            // reference. It is replaced by the capture id on success, because
            // that is what a refund is issued against.
            'provider_reference' => $paypalOrderId,
            // Re-read rather than incremented: the total is authoritative here,
            // and an order edited in admin between attempts must not be captured
            // for the old figure.
            'amount_cents' => $order->grand_total_cents,
            'currency' => $order->currency,
            'raw_response' => array_merge($payment->raw_response ?? [], [
                'mode' => $this->client->mode(),
                'paypal_order_id' => $paypalOrderId,
                'created_at' => now()->toIso8601String(),
            ]),
        ]);

        return $paypalOrderId;
    }

    /**
     * Capture an approved PayPal order and mark ours paid.
     *
     * Rule 3: returns the order untouched if it is already paid, so the browser
     * callback and the webhook can both call this freely.
     *
     * @throws RuntimeException on anything that means the money is not ours.
     */
    public function capture(Order $order, string $paypalOrderId): Order
    {
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $order;
        }

        if (! $this->client->configured()) {
            throw new RuntimeException('PayPal is not available at the moment.');
        }

        $this->assertPayable($order);

        $payment = $this->paymentFor($order);

        // The id has to be one we handed this order. Without this check, a
        // caller could present any PayPal order id they had seen — including
        // one belonging to somebody else's cheaper order — and have it
        // captured against this one.
        $this->assertBelongsToOrder($payment, $paypalOrderId);

        $response = $this->client->captureOrder(
            $paypalOrderId,
            'bsd-capture-'.$order->id.'-'.$paypalOrderId,
        );

        if (! $response) {
            throw new RuntimeException('We could not confirm your payment with PayPal. Please try again.');
        }

        $body = $response->json() ?? [];

        // A capture that was already taken comes back as 422 ORDER_ALREADY_CAPTURED.
        // That is not a failure: it means an earlier attempt (usually the webhook)
        // got there first, so the truth is fetched instead of retried.
        if (! $response->successful()) {
            if ($this->isAlreadyCaptured($body)) {
                $body = $this->client->getOrder($paypalOrderId)?->json() ?? [];
            } else {
                throw new RuntimeException($this->customerMessageFor($body));
            }
        }

        $capture = $this->completedCapture($body);

        if (! $capture) {
            // An eCheck, or a capture held for review: PayPal has taken the
            // payment on but has not cleared it. Saying no money was taken here
            // would be false, and would invite a second payment for an order
            // that is already being paid for, so it is recorded and stated
            // plainly instead. The amount is still checked — an eCheck for the
            // wrong figure is no more acceptable than a cleared one.
            if ($pending = $this->pendingCapture($body)) {
                $this->assertAmountMatches($order, $pending);
                $this->recordPendingCapture($order, $payment, $paypalOrderId, $pending, $body);

                throw new PayPalPaymentPending($this->pendingMessage($pending));
            }

            throw new RuntimeException(
                'PayPal did not complete that payment. No money has been taken — please try again.'
            );
        }

        // Rule 1. The one check that makes the rest of this trustworthy.
        $this->assertAmountMatches($order, $capture);

        return $this->recordCapture($order, $payment, $paypalOrderId, $capture, $body);
    }

    /**
     * Confirm an order from a verified `PAYMENT.CAPTURE.COMPLETED` webhook.
     *
     * The safety net for the case the browser never comes back — the customer
     * closed the tab between approving and our capture call landing. It performs
     * the same verification as the interactive path, because a webhook body is
     * an assertion by PayPal, not proof on its own.
     *
     * @param  array<string, mixed>  $resource  The event's `resource` object.
     */
    public function confirmFromWebhook(array $resource): ?Order
    {
        $order = $this->orderFromWebhook($resource);

        if (! $order) {
            return null;
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $order;
        }

        if (($resource['status'] ?? null) !== 'COMPLETED') {
            return null;
        }

        $capture = [
            'id' => $resource['id'] ?? null,
            'status' => 'COMPLETED',
            'amount' => $resource['amount'] ?? [],
        ];

        try {
            $this->assertPayable($order);
            $this->assertAmountMatches($order, $capture);
        } catch (RuntimeException $e) {
            // Loud, and deliberately not acted on: money has arrived that does
            // not match an order we can confirm. That needs a human, and the
            // order must not be marked paid on a figure we do not recognise.
            Log::error('PayPal webhook capture did not match its order.', [
                'order_number' => $order->order_number,
                'capture_id' => $capture['id'],
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $payment = $this->paymentFor($order);

        return $this->recordCapture($order, $payment, (string) ($resource['supplementary_data']['related_ids']['order_id']
            ?? $payment->raw_response['paypal_order_id']
            ?? ''), $capture, ['webhook' => true] + $resource);
    }

    /**
     * Record an uncleared capture from a verified `PAYMENT.CAPTURE.PENDING`.
     *
     * The order is NOT confirmed — nothing has settled. This exists so that an
     * eCheck we never saw in the browser (the customer closed the tab) is still
     * visible as money on its way rather than an abandoned order whose stock is
     * there for the taking. The matching COMPLETED event confirms it later.
     *
     * @param  array<string, mixed>  $resource  The event's `resource` object.
     */
    public function recordPendingFromWebhook(array $resource): ?Order
    {
        $order = $this->orderFromWebhook($resource);

        if (! $order || $order->payment_status === Order::PAYMENT_PAID) {
            return $order;
        }

        if (($resource['status'] ?? null) !== 'PENDING') {
            return null;
        }

        $capture = [
            'id' => $resource['id'] ?? null,
            'status' => 'PENDING',
            'amount' => $resource['amount'] ?? [],
            'status_details' => $resource['status_details'] ?? [],
        ];

        try {
            $this->assertPayable($order);
            $this->assertAmountMatches($order, $capture);
        } catch (RuntimeException $e) {
            // Same reasoning as the COMPLETED path: an amount we do not
            // recognise is a human's problem, not something to write down as
            // though it were expected.
            Log::error('PayPal pending capture did not match its order.', [
                'order_number' => $order->order_number,
                'capture_id' => $capture['id'],
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $payment = $this->paymentFor($order);

        $this->recordPendingCapture($order, $payment, (string) ($resource['supplementary_data']['related_ids']['order_id']
            ?? $payment->raw_response['paypal_order_id']
            ?? ''), $capture, $resource);

        return $order;
    }

    /** The order a webhook resource names, or null with the reason logged. */
    protected function orderFromWebhook(array $resource): ?Order
    {
        $orderNumber = $resource['custom_id'] ?? $resource['invoice_id'] ?? null;

        if (! is_string($orderNumber) || $orderNumber === '') {
            Log::warning('PayPal webhook capture carried no order reference.', [
                'capture_id' => $resource['id'] ?? null,
            ]);

            return null;
        }

        $order = Order::where('order_number', $orderNumber)->first();

        if (! $order) {
            Log::warning('PayPal webhook named an unknown order.', ['order_number' => $orderNumber]);

            return null;
        }

        return $order;
    }

    /**
     * Refund through PayPal, then record it in our ledger.
     *
     * The provider call goes first on purpose: a refund row that exists without
     * the money having moved is a worse lie than a moved refund we failed to
     * write down (which the PayPal dashboard still evidences).
     *
     * @throws RuntimeException when PayPal refuses the refund.
     */
    public function refund(Order $order, int $amountCents, ?string $reason = null, bool $restock = true, $actor = null)
    {
        $payment = $order->payments()
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->latest('id')
            ->first();

        $captureId = $payment?->paypalCaptureId();

        if (! $payment || ! $captureId) {
            throw new RuntimeException('This order has no captured PayPal payment to refund.');
        }

        $response = $this->client->refundCapture(
            (string) $captureId,
            // A full refund is sent with no amount at all, which is how PayPal
            // wants it and avoids a rounding argument over the last cent.
            $amountCents >= $payment->amount_cents ? null : $this->decimal($amountCents),
            $order->currency,
            'bsd-refund-'.$order->id.'-'.$amountCents.'-'.Str::random(8),
            $reason,
        );

        if (! $response?->successful()) {
            throw new RuntimeException(
                $this->customerMessageFor($response?->json() ?? [], 'PayPal refused that refund.')
            );
        }

        return $this->orders->refund(
            $order,
            $amountCents,
            $reason,
            $restock,
            $response->json('id'),
            $actor,
        );
    }

    // ---------------------------------------------------------------- internals

    /** Rule 4. */
    protected function assertPayable(Order $order): void
    {
        if ($order->payment_status !== Order::PAYMENT_PENDING) {
            throw new RuntimeException('This order is no longer awaiting payment.');
        }

        if ($order->fulfillment_status === Order::FULFILLMENT_CANCELLED) {
            throw new RuntimeException('This order has been cancelled.');
        }

        if ($order->grand_total_cents <= 0) {
            throw new RuntimeException('This order has nothing to pay.');
        }
    }

    protected function assertBelongsToOrder(Payment $payment, string $paypalOrderId): void
    {
        $known = array_filter([
            $payment->provider_reference,
            $payment->raw_response['paypal_order_id'] ?? null,
        ]);

        foreach ($known as $candidate) {
            if (hash_equals((string) $candidate, $paypalOrderId)) {
                return;
            }
        }

        throw new RuntimeException('That payment does not belong to this order.');
    }

    /**
     * Rule 1. Currency and amount both, and the amount to the cent.
     *
     * Compared as integers after conversion, never as floats: 0.1 + 0.2 is the
     * classic way a payment check passes when it should not.
     */
    protected function assertAmountMatches(Order $order, array $capture): void
    {
        $currency = $capture['amount']['currency_code'] ?? null;
        $value = $capture['amount']['value'] ?? null;

        if ($currency !== $order->currency) {
            throw new RuntimeException(
                "PayPal captured {$currency} against a {$order->currency} order."
            );
        }

        if (! is_numeric($value)) {
            throw new RuntimeException('PayPal reported no amount for that capture.');
        }

        $paidCents = (int) round(((float) $value) * 100);

        if ($paidCents !== $order->grand_total_cents) {
            throw new RuntimeException(sprintf(
                'PayPal captured %s but the order total is %s.',
                $this->decimal($paidCents),
                $this->decimal($order->grand_total_cents),
            ));
        }
    }

    /** The COMPLETED capture out of a v2 order body, if there is one. */
    protected function completedCapture(array $body): ?array
    {
        if (($body['status'] ?? null) !== 'COMPLETED') {
            return null;
        }

        foreach ($body['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                if (($capture['status'] ?? null) === 'COMPLETED') {
                    return $capture;
                }
            }
        }

        return null;
    }

    /**
     * The PENDING capture out of a v2 order body, if there is one.
     *
     * Deliberately not gated on the order's own status the way completedCapture
     * is: PayPal reports an eCheck order as COMPLETED while the capture inside
     * it sits at PENDING, and it is the capture that says whether the money has
     * actually arrived.
     */
    protected function pendingCapture(array $body): ?array
    {
        foreach ($body['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                if (($capture['status'] ?? null) === 'PENDING') {
                    return $capture;
                }
            }
        }

        return null;
    }

    /**
     * What to tell a customer whose payment has not cleared.
     *
     * Every branch has to carry the same two facts — the money is on its way,
     * and paying again is the wrong move — because the customer is staring at a
     * checkout page that did not confirm.
     */
    protected function pendingMessage(array $capture): string
    {
        $reason = $capture['status_details']['reason'] ?? null;

        return match ($reason) {
            'ECHECK' => 'PayPal accepted your payment as an eCheque, which takes a few business days to '
                .'clear. Your order is saved and we will confirm it as soon as the money arrives — '
                .'please do not pay for it again.',
            'PENDING_REVIEW', 'RISK_REVIEW' => 'PayPal is reviewing your payment before releasing it. Your '
                .'order is saved and we will confirm it as soon as PayPal clears the payment — please do '
                .'not pay for it again.',
            default => 'PayPal has taken your payment but has not cleared it yet. Your order is saved and '
                .'we will confirm it as soon as the money arrives — please do not pay for it again.',
        };
    }

    /**
     * Write an uncleared capture down without marking the order paid.
     *
     * The payment row keeps its pending status — nothing has settled — but it
     * gains the capture id, so the clearing capture is recognisable when it
     * arrives and an administrator can see the order is not merely abandoned.
     * Idempotent: the browser and the PENDING webhook both land here.
     */
    protected function recordPendingCapture(
        Order $order,
        Payment $payment,
        string $paypalOrderId,
        array $capture,
        array $body,
    ): void {
        $captureId = $capture['id'] ?? null;
        $alreadyKnown = ($payment->raw_response['capture_id'] ?? null) === $captureId;

        $payment->update([
            'provider' => Payment::PROVIDER_PAYPAL,
            'method' => Payment::PROVIDER_PAYPAL,
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $captureId ?: $payment->provider_reference,
            'raw_response' => array_merge($payment->raw_response ?? [], [
                'mode' => $this->client->mode(),
                'paypal_order_id' => $paypalOrderId ?: ($payment->raw_response['paypal_order_id'] ?? null),
                'capture_id' => $captureId,
                'capture_status' => 'PENDING',
                'pending_reason' => $capture['status_details']['reason'] ?? null,
                'captured_amount' => $capture['amount'] ?? null,
                'payer' => $body['payer'] ?? ($payment->raw_response['payer'] ?? null),
                'pending_since' => $payment->raw_response['pending_since'] ?? now()->toIso8601String(),
            ]),
        ]);

        if ($alreadyKnown) {
            return;
        }

        // Visible in the admin panel on purpose. Without it the order looks like
        // any other unpaid one, and the stock it is holding is exactly what an
        // administrator would release — while the money is still on its way.
        $this->orders->note($order, sprintf(
            'PayPal payment awaiting clearance (%s). Capture %s is PENDING — do not cancel or restock '
            .'until PayPal reports it completed or denied.',
            $capture['status_details']['reason'] ?? 'no reason given',
            $captureId ?: 'unknown',
        ));

        Log::warning('PayPal capture is pending rather than completed.', [
            'order_number' => $order->order_number,
            'capture_id' => $captureId,
            'reason' => $capture['status_details']['reason'] ?? null,
        ]);
    }

    /**
     * Write the capture down and hand off to OrderService.
     *
     * Rule 2 and Rule 3 live here together: the whole thing is one transaction,
     * and it re-reads the order under a lock first, so two callers arriving at
     * once (browser and webhook) cannot both mark it paid.
     */
    protected function recordCapture(
        Order $order,
        Payment $payment,
        string $paypalOrderId,
        array $capture,
        array $body,
    ): Order {
        $paid = DB::transaction(function () use ($order, $payment, $paypalOrderId, $capture, $body) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === Order::PAYMENT_PAID) {
                return $locked ?? $order;
            }

            $payment->update([
                'provider' => Payment::PROVIDER_PAYPAL,
                'method' => Payment::PROVIDER_PAYPAL,
                // Now the capture id: the reference a refund is issued against.
                'provider_reference' => $capture['id'] ?? $payment->provider_reference,
                'raw_response' => array_merge($payment->raw_response ?? [], [
                    'mode' => $this->client->mode(),
                    'paypal_order_id' => $paypalOrderId ?: ($payment->raw_response['paypal_order_id'] ?? null),
                    'capture_id' => $capture['id'] ?? null,
                    'capture_status' => $capture['status'] ?? null,
                    'captured_amount' => $capture['amount'] ?? null,
                    // Kept because a disputed charge is argued with the payer
                    // record, and it is not reconstructable later.
                    'payer' => $body['payer'] ?? null,
                    'captured_at' => now()->toIso8601String(),
                ]),
            ]);

            // Rule 2: stock, history and the notification all happen in there.
            return $this->orders->markPaid($locked, $payment);
        });

        return $paid;
    }

    /** PayPal's 422 for a capture that has already been taken. */
    protected function isAlreadyCaptured(array $body): bool
    {
        if (($body['name'] ?? null) === 'ORDER_ALREADY_CAPTURED') {
            return true;
        }

        foreach ($body['details'] ?? [] as $detail) {
            if (($detail['issue'] ?? null) === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a PayPal error into something a customer can act on.
     *
     * Only the handful of issues a customer can actually do something about are
     * named; everything else stays generic, because PayPal's raw `details` text
     * is written for integrators and often mentions fields the customer has
     * never seen.
     */
    protected function customerMessageFor(array $body, ?string $fallback = null): string
    {
        $issue = $body['details'][0]['issue'] ?? $body['name'] ?? null;

        return match ($issue) {
            'INSTRUMENT_DECLINED' => 'PayPal declined that payment method. Please choose another one in the PayPal window.',
            'PAYER_ACTION_REQUIRED' => 'PayPal needs you to confirm something before the payment can complete.',
            'PAYER_CANNOT_PAY', 'TRANSACTION_REFUSED' => 'PayPal could not complete that payment. Please try another method.',
            'ORDER_NOT_APPROVED' => 'That payment was not approved in PayPal.',
            default => $fallback ?? 'We could not complete your payment with PayPal. No money has been taken.',
        };
    }

    /** The order's existing pending payment row, or a new one. */
    protected function paymentFor(Order $order): Payment
    {
        return $order->payments()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
            ->latest('id')
            ->first()
            ?? $order->payments()->create([
                'provider' => Payment::PROVIDER_PAYPAL,
                'method' => Payment::PROVIDER_PAYPAL,
                'status' => Payment::STATUS_PENDING,
                'amount_cents' => $order->grand_total_cents,
                'currency' => $order->currency,
            ]);
    }

    /**
     * The v2 Orders payload.
     *
     * The breakdown is itemised so the customer sees the same figures on
     * paypal.com as on our checkout — a total that appears out of nowhere is a
     * common reason for abandonment at this exact step.
     */
    protected function orderPayload(Order $order): array
    {
        $order->loadMissing(['items', 'shippingAddress']);

        $purchaseUnit = [
            // Both, deliberately: `custom_id` is what comes back on the capture
            // webhook, and `invoice_id` is what makes PayPal itself refuse a
            // second payment for the same order.
            'custom_id' => $order->order_number,
            'invoice_id' => $order->order_number,
            'description' => Str::limit('BulkScrubsDirect order '.$order->order_number, 127, ''),
            'amount' => $this->amountFor($order),
        ];

        $items = $this->itemsFor($order);

        // Only sent alongside a breakdown. PayPal validates the two against each
        // other, and items without an item_total to agree with is a rejection.
        if ($items && isset($purchaseUnit['amount']['breakdown'])) {
            $purchaseUnit['items'] = $items;
        }

        if ($shipping = $this->shippingFor($order)) {
            $purchaseUnit['shipping'] = $shipping;
        }

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [$purchaseUnit],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => 'BulkScrubsDirect',
                        'locale' => 'en-CA',
                        'landing_page' => 'LOGIN',
                        // "Pay Now" rather than "Continue": the total is already
                        // final here, so a second review step on PayPal's side
                        // is just another place to drop out.
                        'user_action' => 'PAY_NOW',
                        'shipping_preference' => isset($purchaseUnit['shipping'])
                            ? 'SET_PROVIDED_ADDRESS'
                            // Pickup orders have no address to show, and letting
                            // PayPal collect one would put a second, different
                            // address on the order.
                            : 'NO_SHIPPING',
                    ],
                ],
            ],
        ];
    }

    /**
     * The amount, with an itemised breakdown where the figures balance.
     *
     * PayPal rejects a breakdown whose parts do not sum exactly to the total, so
     * the discount is derived as the remainder rather than trusted from the
     * order. If that remainder comes out negative — which would mean our own
     * figures disagree — the breakdown is dropped and the bare total is sent.
     * A plainer PayPal receipt is a far better outcome than a checkout that
     * cannot complete.
     */
    protected function amountFor(Order $order): array
    {
        $amount = [
            'currency_code' => $order->currency,
            'value' => $this->decimal($order->grand_total_cents),
        ];

        $itemTotal = $order->items->sum(fn ($item) => $item->unit_retail_cents * $item->qty);

        $discount = $itemTotal + $order->shipping_cents + $order->tax_cents - $order->grand_total_cents;

        if ($itemTotal <= 0 || $discount < 0) {
            Log::warning('PayPal breakdown omitted: order figures do not balance.', [
                'order_number' => $order->order_number,
                'item_total_cents' => $itemTotal,
                'implied_discount_cents' => $discount,
            ]);

            return $amount;
        }

        $amount['breakdown'] = [
            'item_total' => ['currency_code' => $order->currency, 'value' => $this->decimal($itemTotal)],
            'shipping' => ['currency_code' => $order->currency, 'value' => $this->decimal($order->shipping_cents)],
            'tax_total' => ['currency_code' => $order->currency, 'value' => $this->decimal($order->tax_cents)],
            'discount' => ['currency_code' => $order->currency, 'value' => $this->decimal($discount)],
        ];

        return $amount;
    }

    /** @return array<int, array<string, mixed>> */
    protected function itemsFor(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'name' => Str::limit($item->product_name, 127, ''),
            'description' => Str::limit(
                collect([$item->color_name, $item->size_name, $item->secondary_size_name])
                    ->filter()
                    ->implode(' · '),
                127,
                '',
            ) ?: null,
            'sku' => Str::limit($item->variant_sku, 127, ''),
            'quantity' => (string) $item->qty,
            'unit_amount' => [
                'currency_code' => $order->currency,
                'value' => $this->decimal($item->unit_retail_cents),
            ],
            // Scrubs are goods, and PayPal uses this for its own tax reporting.
            'category' => 'PHYSICAL_GOODS',
        ])->map(fn ($item) => array_filter($item, fn ($v) => $v !== null))->values()->all();
    }

    /** Pre-filling PayPal with the address the order will actually ship to. */
    protected function shippingFor(Order $order): ?array
    {
        if ($order->fulfillment_type !== Order::TYPE_SHIP) {
            return null;
        }

        $address = $order->shippingAddress;

        if (! $address) {
            return null;
        }

        return [
            'name' => ['full_name' => Str::limit(trim($address->first_name.' '.$address->last_name), 300, '')],
            'address' => array_filter([
                'address_line_1' => $address->line1,
                'address_line_2' => $address->line2,
                'admin_area_2' => $address->city,
                'admin_area_1' => $address->province,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country ?: 'CA',
            ]),
        ];
    }

    /** Cents to the decimal string PayPal wants — "12.34", never a float. */
    protected function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
