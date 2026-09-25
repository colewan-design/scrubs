<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\PayPalPaymentPending;
use App\Services\Payments\PayPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal Checkout endpoints (§4).
 *
 * Three of them, and the split matters:
 *
 *  - `create` opens a PayPal order against one of ours. Used for a retry, after
 *    the customer closed the PayPal window; the first attempt gets its id from
 *    the /checkout response instead.
 *  - `capture` is the browser telling us the customer approved. It is the happy
 *    path, and it is not trusted: the amount is verified against our own order
 *    before anything is marked paid.
 *  - `webhook` is PayPal telling us the same thing directly, for the case the
 *    browser never came back. Signature-verified, and idempotent with `capture`.
 *
 * Note what is NOT here: no amount, no currency and no order total is ever read
 * from the request. All three come from the order row.
 */
class PayPalController extends Controller
{
    public function __construct(
        protected PayPalService $paypal,
        protected PayPalClient $client,
    ) {}

    /** Open a fresh PayPal order for an order still awaiting payment. */
    public function create(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        try {
            return response()->json(['order_id' => $this->paypal->createOrderFor($order)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Capture an approved PayPal order.
     *
     * The PayPal order id is taken from the request but never trusted on its
     * own — PayPalService checks it is one we issued for this order before
     * capturing anything against it.
     */
    public function capture(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'paypal_order_id' => ['required', 'string', 'max:64'],
        ]);

        $pending = null;

        try {
            $order = $this->paypal->capture($order, $data['paypal_order_id']);
        } catch (PayPalPaymentPending $e) {
            // 202, not 422: the payment has not failed, it has not cleared. A
            // 422 here would put this in front of the customer as an error and
            // invite them to pay a second time for money already on its way.
            $pending = $e->getMessage();
            $order->refresh();
        } catch (RuntimeException $e) {
            // 422, not 500: every message that reaches here is something the
            // customer can act on, and the checkout page shows it as-is.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_filter([
            'order' => OrderResource::make($order->load([
                'items.variant.product.images', 'taxes', 'addresses', 'statusHistory',
            ]))->toArray($request),
            'payment_pending' => (bool) $pending,
            'message' => $pending,
        ]), $pending ? 202 : 200);
    }

    /**
     * PayPal's own notification of a completed capture.
     *
     * Always answers 200 once the signature checks out, including for events we
     * do not act on. PayPal retries anything else for days, and a retry storm
     * over an event we were never going to handle is noise that hides the
     * deliveries that do matter.
     */
    public function webhook(Request $request): JsonResponse
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            // Unconfigured means unverifiable, and an unverifiable payment
            // notification is worth nothing — anyone could post it.
            Log::warning('PayPal webhook received with no PAYPAL_WEBHOOK_ID configured; ignored.');

            return response()->json(['message' => 'Webhooks are not configured.'], 503);
        }

        $event = $request->json()->all();

        $verification = $this->client->verifyWebhookSignature([
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME', ''),
            'cert_url' => $request->header('PAYPAL-CERT-URL', ''),
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO', ''),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
        ], $event, $webhookId);

        if ($verification?->json('verification_status') !== 'SUCCESS') {
            Log::warning('PayPal webhook failed signature verification.', [
                'event_type' => $event['event_type'] ?? null,
                'event_id' => $event['id'] ?? null,
                'status' => $verification?->json('verification_status'),
            ]);

            return response()->json(['message' => 'Signature verification failed.'], 400);
        }

        $type = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];

        match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->paypal->confirmFromWebhook($resource),

            // Recorded, but the order stays unpaid: an eCheck or a held capture
            // is money on its way, not money arrived. Worth writing down anyway,
            // because otherwise the order is indistinguishable from an abandoned
            // one and its reserved stock is the obvious thing to release.
            'PAYMENT.CAPTURE.PENDING' => $this->paypal->recordPendingFromWebhook($resource),

            // Recorded rather than acted on. A capture we never completed cannot
            // have moved our order, and a denial after the fact needs a human to
            // look at why — automatically cancelling would release stock for an
            // order the customer may be about to retry.
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.REVERSED',
            'PAYMENT.CAPTURE.REFUNDED' => Log::warning("PayPal reported {$type}.", [
                'capture_id' => $resource['id'] ?? null,
                'order_number' => $resource['custom_id'] ?? $resource['invoice_id'] ?? null,
                'amount' => $resource['amount'] ?? null,
            ]),

            default => null,
        };

        return response()->json(['received' => true]);
    }

    /**
     * Ownership, on the same terms as the order lookup: the signed-in owner, or
     * the email the order was placed with. Order numbers are sequential and
     * therefore guessable, so an unproved caller must not be able to open a
     * payment against somebody else's order.
     */
    protected function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        $ownsIt = $user && $order->user_id === $user->id;
        $knowsEmail = $request->filled('email')
            && hash_equals(strtolower($order->email), strtolower((string) $request->input('email')));

        abort_unless($ownsIt || $knowsEmail, 404);
    }
}
