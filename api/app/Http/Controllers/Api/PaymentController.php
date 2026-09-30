<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Re-open the payment for an order that has already been placed (§4).
 *
 * Checkout hands back a payment session with the order it creates, so the happy
 * path never comes here. This endpoint is for the two ways that path breaks:
 *
 *  1. The provider was unreachable at placement. The order exists, the stock is
 *     held, and the customer needs a retry that does not place a second order.
 *
 *  2. The customer closed the tab, or came back to the confirmation page later,
 *     and wants to finish paying.
 *
 * It never creates an order and never moves money — it hands back the same
 * provider-side payment, which is why StripeGateway::prepare() has to be
 * idempotent. Ownership is proved exactly as it is for reading the order.
 */
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function session(Request $request, Order $order): JsonResponse
    {
        // `input` rather than `query`: this is a POST, and a guest proving
        // ownership sends the address in the body like every other field.
        abort_unless(
            $order->canBeViewedBy($request->user(), (string) $request->input('email')),
            404,
        );

        if (! $order->awaitingPayment()) {
            // Already settled, or cancelled out from under them. Either way
            // there is nothing to pay, and saying so is better than handing
            // back a session that would take a second payment.
            return response()->json([
                'message' => 'This order is no longer awaiting payment.',
                'status' => $order->status,
            ], 422);
        }

        try {
            $session = $this->payments->prepare($order);
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $session) {
            return response()->json([
                'message' => 'This order is settled outside the website.',
                'status' => $order->status,
            ], 422);
        }

        return response()->json(['payment' => $session->toArray()]);
    }
}
