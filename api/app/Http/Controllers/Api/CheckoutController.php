<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoneyResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CartService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PayPalService;
use App\Services\Shipping\Destination;
use App\Services\Shipping\Parcel;
use App\Services\Shipping\ShippingService;
use App\Services\Tax\TaxService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Checkout (§4, §5, §6, §7).
 *
 * Two endpoints, deliberately: `quote` prices a destination so the customer can
 * see shipping and tax before committing, and `store` places the order. Both
 * price server-side from the same services — the client posts a destination and
 * a choice, never an amount.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $carts,
        protected OrderService $orders,
        protected ShippingService $shipping,
        protected TaxService $tax,
        protected Settings $settings,
        protected PayPalService $paypal,
    ) {}

    /** Shipping options and tax for a destination, before anything is committed. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fulfillment_type' => ['nullable', Rule::in([Order::TYPE_SHIP, Order::TYPE_PICKUP])],
            'province' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2'],
            'shipping_option' => ['nullable', 'string', 'max:64'],
        ]);

        $cart = $this->carts->resolve($request->user(), $request->header('X-Cart-Token'));
        $priceQuote = $this->carts->quote($cart, $request->user());

        $isPickup = ($data['fulfillment_type'] ?? Order::TYPE_SHIP) === Order::TYPE_PICKUP;

        $destination = $isPickup
            ? new Destination($this->settings->string('pickup.province', 'ON'))
            : Destination::fromArray($data);

        $parcel = Parcel::fromQuotedLines($priceQuote->lines, $priceQuote->subtotalCents);

        $options = $isPickup
            ? [$this->shipping->pickupOption()]
            : $this->shipping->options($destination, $parcel, $priceQuote->retailSubtotalCents);

        // The selected option, or the cheapest as a sensible default.
        //
        // Pickup is deliberately excluded from that default. It always costs
        // nothing, so "cheapest" would silently switch a delivery order to
        // collection — and quote a shipping cost and freight tax of zero for an
        // order that is going to be posted.
        $selectable = $isPickup
            ? $options
            : array_filter($options, fn ($o) => $o->code !== ShippingService::PICKUP_CODE);

        $selected = collect($options)->firstWhere('code', $data['shipping_option'] ?? null)
            ?? collect($selectable)->sortBy(fn ($o) => $o->costCents)->first();

        $shippingCents = $selected?->costCents ?? 0;

        $taxQuote = $this->tax->calculate(
            $destination->province,
            $priceQuote->subtotalCents,
            $shippingCents,
        );

        $grandTotal = $priceQuote->subtotalCents + $shippingCents + $taxQuote->totalCents();

        return response()->json([
            'cart' => $priceQuote->toArray(),
            'fulfillment_type' => $isPickup ? Order::TYPE_PICKUP : Order::TYPE_SHIP,
            'shipping_options' => array_map(fn ($o) => $o->toArray() + [
                'cost' => MoneyResource::make($o->costCents),
            ], $options),
            'selected_shipping_option' => $selected?->code,
            'shipping' => MoneyResource::make($shippingCents),
            'tax' => [
                'province' => $taxQuote->province,
                'lines' => array_map(
                    fn ($l) => $l + ['amount' => MoneyResource::make($l['amount_cents'])],
                    $taxQuote->toArray()['lines'],
                ),
                'total_cents' => $taxQuote->totalCents(),
                'total' => MoneyResource::make($taxQuote->totalCents()),
            ],
            'grand_total' => MoneyResource::make($grandTotal),
            // Weights are outstanding client data; surfaced so the admin can see
            // why live rating is not yet trustworthy rather than guessing.
            'weights_complete' => $parcel->hasCompleteWeights(),
            'pickup' => $this->settings->bool('pickup.enabled') ? [
                'address' => $this->settings->string('pickup.address'),
                'hours' => $this->settings->string('pickup.hours'),
                'lead_time' => $this->settings->string('pickup.lead_time'),
            ] : null,
            'etransfer' => $this->settings->bool('orders.etransfer_enabled') ? [
                'instructions' => $this->settings->string('orders.etransfer_instructions'),
            ] : null,
            // Lets the storefront render only the methods that will actually
            // work, the same way /auth/providers does for social sign-in. The
            // client id is public by design — it is what the PayPal JS SDK is
            // loaded with — but the secret never leaves the server.
            'paypal' => $this->paypal->publicConfig(),
        ]);
    }

    /** Place the order. */
    public function store(Request $request): JsonResponse
    {
        $isPickup = $request->input('fulfillment_type') === Order::TYPE_PICKUP;

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'fulfillment_type' => ['nullable', Rule::in([Order::TYPE_SHIP, Order::TYPE_PICKUP])],
            'shipping_option' => [Rule::requiredIf(! $isPickup), 'string', 'max:64'],
            // Constrained rather than free text: this string becomes the payment
            // row's provider, and an unrecognised one would create an order that
            // nothing knows how to settle.
            'payment_method' => ['nullable', Rule::in(Payment::CHECKOUT_PROVIDERS)],

            'shipping_address' => [Rule::requiredIf(! $isPickup), 'array'],
            'shipping_address.first_name' => [Rule::requiredIf(! $isPickup), 'string', 'max:80'],
            'shipping_address.last_name' => [Rule::requiredIf(! $isPickup), 'string', 'max:80'],
            'shipping_address.company' => ['nullable', 'string', 'max:120'],
            'shipping_address.line1' => [Rule::requiredIf(! $isPickup), 'string', 'max:160'],
            'shipping_address.line2' => ['nullable', 'string', 'max:160'],
            'shipping_address.city' => [Rule::requiredIf(! $isPickup), 'string', 'max:80'],
            'shipping_address.province' => [Rule::requiredIf(! $isPickup), 'string', 'size:2'],
            'shipping_address.postal_code' => [Rule::requiredIf(! $isPickup), 'string', 'max:10'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'shipping_address.phone' => ['nullable', 'string', 'max:40'],

            'billing_address' => ['nullable', 'array'],
        ]);

        // Asking to pay by PayPal while it is switched off would place an order
        // the customer then has no way to settle, so it is refused up front
        // rather than silently downgraded to e-Transfer.
        $wantsPayPal = ($data['payment_method'] ?? null) === Payment::PROVIDER_PAYPAL;

        if ($wantsPayPal && ! $this->paypal->enabled()) {
            return response()->json([
                'message' => 'PayPal is not available at the moment. Please choose another payment method.',
                'errors' => ['payment_method' => ['PayPal is currently unavailable.']],
            ], 422);
        }

        $cart = $this->carts->resolve($request->user(), $request->header('X-Cart-Token'));

        try {
            $order = $this->orders->place($cart, $request->user(), $data);
        } catch (RuntimeException $e) {
            // Stock moving under a customer mid-checkout is an expected outcome,
            // not a server error — 422 so the frontend can show it on the form.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'order' => OrderResource::make($order->load([
                'items', 'taxes', 'addresses', 'statusHistory',
            ]))->toArray($request),
        ];

        // The order exists and holds its stock from here on. A PayPal order is
        // opened against it in the same response so the buttons have an id to
        // hand the SDK without a second round trip.
        if ($wantsPayPal) {
            try {
                $payload['paypal'] = ['order_id' => $this->paypal->createOrderFor($order)];
            } catch (RuntimeException $e) {
                // Deliberately still a 201. The order is placed and priced; only
                // the payment handover failed. Reporting an error here would
                // leave the customer thinking nothing happened while their stock
                // is reserved and the admin can see the order.
                $payload['paypal'] = null;
                $payload['payment_error'] = $e->getMessage();
            }
        }

        return response()->json($payload, 201);
    }
}
