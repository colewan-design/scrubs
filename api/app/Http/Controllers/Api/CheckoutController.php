<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoneyResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CartService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
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
        protected PaymentService $payments,
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
            // What the customer may actually pay with, decided server-side from
            // credentials plus the admin switch. The storefront renders this
            // list rather than deciding for itself which methods exist.
            'payment_methods' => $this->payments->availableMethods(),
            // PayPal is reported separately because it is not a PaymentGateway:
            // the button is driven by PayPal's own JS SDK, which needs the
            // client id. That id is public by design — it is what the SDK is
            // loaded with — but the secret never leaves the server.
            'paypal' => $this->paypal->publicConfig(),
        ]);
    }

    /** Place the order. */
    public function store(Request $request): JsonResponse
    {
        $isPickup = $request->input('fulfillment_type') === Order::TYPE_PICKUP;

        // Billing is optional: leave it out and the order bills where it ships.
        // But once the customer says it is somewhere else, it has to be a whole
        // address. The storefront posts an unticked-and-untouched billing form
        // as an address of blanks, and without these rules that went all the
        // way to the database before anything objected.
        $hasBilling = ! empty($request->input('billing_address'));

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
            'billing_address.first_name' => [Rule::requiredIf($hasBilling), 'string', 'max:80'],
            'billing_address.last_name' => [Rule::requiredIf($hasBilling), 'string', 'max:80'],
            'billing_address.company' => ['nullable', 'string', 'max:120'],
            'billing_address.line1' => [Rule::requiredIf($hasBilling), 'string', 'max:160'],
            'billing_address.line2' => ['nullable', 'string', 'max:160'],
            'billing_address.city' => [Rule::requiredIf($hasBilling), 'string', 'max:80'],
            'billing_address.province' => [Rule::requiredIf($hasBilling), 'string', 'size:2'],
            'billing_address.postal_code' => [Rule::requiredIf($hasBilling), 'string', 'max:10'],
            'billing_address.country' => ['nullable', 'string', 'size:2'],
            'billing_address.phone' => ['nullable', 'string', 'max:40'],
        ], [], $this->addressFieldNames());

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
            // A database failure is neither expected nor theirs to read.
            return $this->refusal(
                $e,
                'We could not place your order. Please try again, or contact us if it keeps happening.',
            );
        }

        // The order now exists and is holding stock. Opening the provider-side
        // payment is a separate, failable step that happens AFTER that — never
        // before, because an intent created against an order that then fails to
        // save is an authorisation with nothing behind it.
        //
        // A provider outage here is therefore not a failed checkout. The order
        // stands, and the storefront is told to offer a retry against
        // /orders/{order}/payment rather than placing a second order.
        $session = null;
        $paymentError = null;

        try {
            $session = $this->payments->prepare($order);
        } catch (PaymentException $e) {
            report($e);
            $paymentError = $e->getMessage();
        }

        $payload = [
            'order' => OrderResource::make($order->load([
                'items', 'taxes', 'addresses', 'statusHistory', 'payments',
            ]))->toArray($request),
        ];

        if ($session !== null) {
            $payload['payment'] = $session->toArray();
        }

        if ($paymentError !== null) {
            $payload['payment_error'] = $paymentError;
        }

        // PayPal is not a PaymentGateway, so prepare() above returns null for
        // it and the order is opened against PayPal here instead — in the same
        // response, so the buttons have an id to hand the SDK without a second
        // round trip.
        if ($wantsPayPal) {
            try {
                $payload['paypal'] = ['order_id' => $this->paypal->createOrderFor($order)];
            } catch (RuntimeException $e) {
                // Deliberately still a 201. The order is placed and priced; only
                // the payment handover failed. Reporting an error here would
                // leave the customer thinking nothing happened while their stock
                // is reserved and the admin can see the order.
                $payload['paypal'] = null;
                $payload['payment_error'] = $this->customerMessage(
                    $e,
                    'Your order was placed, but we could not open PayPal. Please try again.',
                );
            }
        }

        return response()->json($payload, 201);
    }

    /**
     * What to call each address field in a validation message.
     *
     * The storefront prints the message under the field it is about, so "The
     * postal code field is required." says everything. Left to itself Laravel
     * names the field after its key: "The billing address.postal code field".
     *
     * @return array<string, string>
     */
    protected function addressFieldNames(): array
    {
        $names = [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'company' => 'company',
            'line1' => 'address',
            'line2' => 'apartment or suite',
            'city' => 'city',
            'province' => 'province',
            'postal_code' => 'postal code',
            'country' => 'country',
            'phone' => 'phone number',
        ];

        $attributes = [];

        foreach (['shipping_address', 'billing_address'] as $address) {
            foreach ($names as $field => $name) {
                $attributes["{$address}.{$field}"] = $name;
            }
        }

        return $attributes;
    }
}
