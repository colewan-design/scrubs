<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPal Checkout (§4).
 *
 * The assertions that matter are the ones proving the integration does not
 * believe PayPal, or the browser, about money: a capture is only accepted for
 * the exact amount of the order it names, only for a PayPal order we issued
 * against it, and only once.
 *
 * Every test fakes HTTP. Nothing here may reach api-m.paypal.com — the
 * credentials in this project are LIVE, and a test suite that can spend real
 * money is a test suite nobody dares run.
 */
class PayPalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A stray call must fail loudly rather than quietly going to the
        // internet with live credentials.
        Http::preventStrayRequests();

        config([
            'services.paypal.mode' => 'sandbox',
            'services.paypal.base_url' => 'https://api-m.sandbox.paypal.com',
            'services.paypal.client_id' => 'test-client-id',
            'services.paypal.client_secret' => 'test-client-secret',
            'services.paypal.webhook_id' => null,
        ]);

        // The access token is cached across calls by design; each test starts
        // from a clean slate so the token fake is always exercised. Flushed
        // BEFORE the switch is set, because Settings caches too.
        Cache::flush();

        app(Settings::class)->set('payments.paypal_enabled', true);
    }

    // ------------------------------------------------------------- fixtures

    protected ?string $cartToken = null;

    protected function setUpCanada(): void
    {
        TaxRate::create([
            'province' => 'ON', 'tax_type' => 'HST', 'applies_to' => 'goods',
            'rate_bps' => 1300, 'is_active' => true,
        ]);

        $zone = ShippingZone::create([
            'name' => 'Canada', 'provinces' => [], 'position' => 0, 'is_active' => true,
        ]);

        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'name' => 'Standard', 'rate_cents' => 1500,
            'is_free' => false, 'position' => 0, 'is_active' => true,
        ]);
    }

    protected function variant(int $priceCents = 5000, int $stock = 50): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => $priceCents]))
            ->create(['stock_qty' => $stock, 'reserved_qty' => 0]);
    }

    protected function cartHeaders(): array
    {
        return $this->cartToken ? ['X-Cart-Token' => $this->cartToken] : [];
    }

    protected function addToCart(ProductVariant $variant, int $qty): void
    {
        $response = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => $qty]);

        $this->cartToken = $response->json('cart_token') ?: $this->cartToken;
    }

    protected function address(): array
    {
        return [
            'first_name' => 'Dana', 'last_name' => 'Reid',
            'line1' => '10 Queen St W', 'city' => 'Toronto',
            'province' => 'ON', 'postal_code' => 'M5H 2N2', 'country' => 'CA',
        ];
    }

    /** A cart, a quote and a placed PayPal order. Returns the order. */
    protected function placePayPalOrder(?User $user = null): Order
    {
        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 2);

        $quote = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $client = $user ? $this->actingAs($user) : $this;

        $number = $client->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.test',
                'shipping_option' => $quote->json('shipping_options.0.code'),
                'shipping_address' => $this->address(),
                'payment_method' => 'paypal',
            ])
            ->assertCreated()
            ->json('order.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    // ---------------------------------------------------------- HTTP fakes

    protected function fakeToken(): array
    {
        return ['*/v1/oauth2/token' => Http::response([
            'access_token' => 'fake-token', 'expires_in' => 32400,
        ])];
    }

    protected function fakeCreate(string $paypalOrderId = 'PP-ORDER-1'): array
    {
        return ['*/v2/checkout/orders' => Http::response(['id' => $paypalOrderId, 'status' => 'CREATED'])];
    }

    /** A COMPLETED capture body, for whatever amount the test wants to claim. */
    protected function captureBody(string $value, string $currency = 'CAD', string $captureId = 'CAP-1'): array
    {
        return [
            'id' => 'PP-ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => $captureId,
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => $currency, 'value' => $value],
                ]]],
            ]],
            'payer' => ['email_address' => 'buyer@example.test'],
        ];
    }

    protected function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    // -------------------------------------------------------------- offering

    public function test_paypal_is_offered_at_checkout_when_configured_and_enabled(): void
    {
        Http::fake($this->fakeToken());
        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON'])
            ->assertOk()
            ->assertJsonPath('paypal.client_id', 'test-client-id')
            ->assertJsonPath('paypal.mode', 'sandbox')
            ->assertJsonPath('paypal.currency', 'CAD');
    }

    /**
     * The admin switch is the second gate. Credentials alone must not put a
     * PayPal button in front of a customer.
     */
    public function test_paypal_is_withheld_while_the_admin_switch_is_off(): void
    {
        app(Settings::class)->set('payments.paypal_enabled', false);

        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON'])
            ->assertOk()
            ->assertJsonPath('paypal', null);
    }

    public function test_paypal_is_withheld_when_no_credentials_are_configured(): void
    {
        config(['services.paypal.client_id' => null, 'services.paypal.client_secret' => null]);

        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON'])
            ->assertOk()
            ->assertJsonPath('paypal', null);
    }

    /** Asking to pay by a method that is switched off must fail before an order exists. */
    public function test_checking_out_with_paypal_while_it_is_disabled_is_rejected(): void
    {
        app(Settings::class)->set('payments.paypal_enabled', false);

        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $quote = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.test',
                'shipping_option' => $quote->json('shipping_options.0.code'),
                'shipping_address' => $this->address(),
                'payment_method' => 'paypal',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::count());
    }

    public function test_an_unrecognised_payment_method_is_rejected(): void
    {
        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $quote = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.test',
                'shipping_option' => $quote->json('shipping_options.0.code'),
                'shipping_address' => $this->address(),
                'payment_method' => 'bitcoin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    // ------------------------------------------------------------- placement

    /**
     * The order is created here, priced server-side, before PayPal is told
     * anything — and the amount PayPal is asked for is the one on that order.
     */
    public function test_placing_a_paypal_order_opens_a_paypal_order_for_the_server_side_total(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(Payment::PROVIDER_PAYPAL, $order->payments()->value('provider'));
        $this->assertSame('PP-ORDER-1', $order->payments()->value('provider_reference'));

        Http::assertSent(function ($request) use ($order) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $unit = $request['purchase_units'][0];

            return $unit['amount']['value'] === $this->decimal($order->grand_total_cents)
                && $unit['amount']['currency_code'] === 'CAD'
                // Both references, so the capture webhook can find the order.
                && $unit['custom_id'] === $order->order_number
                && $unit['invoice_id'] === $order->order_number;
        });
    }

    /** The itemised breakdown must sum to the total, or PayPal rejects it. */
    public function test_the_paypal_breakdown_sums_to_the_order_total(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        Http::assertSent(function ($request) use ($order) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $breakdown = $request['purchase_units'][0]['amount']['breakdown'] ?? null;

            if (! $breakdown) {
                return false;
            }

            $cents = fn (string $key) => (int) round(((float) $breakdown[$key]['value']) * 100);

            $sum = $cents('item_total') + $cents('shipping') + $cents('tax_total') - $cents('discount');

            return $sum === $order->grand_total_cents;
        });
    }

    /**
     * A PayPal handover that fails must not throw the order away. The stock is
     * already reserved and the admin can see it; the customer is told, and the
     * order stands.
     */
    public function test_a_failed_paypal_handover_still_leaves_the_order_placed(): void
    {
        Http::fake($this->fakeToken() + [
            '*/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);

        $this->setUpCanada();
        $this->addToCart($this->variant(5000), 1);

        $quote = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $response = $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.test',
                'shipping_option' => $quote->json('shipping_options.0.code'),
                'shipping_address' => $this->address(),
                'payment_method' => 'paypal',
            ])
            ->assertCreated()
            ->assertJsonPath('paypal', null);

        $this->assertNotEmpty($response->json('payment_error'));
        $this->assertSame(1, Order::count());
        $this->assertSame(Order::PAYMENT_PENDING, Order::first()->payment_status);
    }

    // --------------------------------------------------------------- capture

    public function test_a_matching_capture_marks_the_order_paid_and_commits_stock(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();
        $variantId = $order->items->first()->product_variant_id;
        $before = ProductVariant::find($variantId);

        Http::fake($this->fakeToken() + [
            '*/v2/checkout/orders/PP-ORDER-1/capture' => Http::response(
                $this->captureBody($this->decimal($order->grand_total_cents))
            ),
        ]);

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
            'email' => 'dana@example.test',
        ])->assertOk()->assertJsonPath('order.payment_status', Order::PAYMENT_PAID);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        $payment = $order->payments()->first();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        // The reference becomes the capture id — what a refund is issued against.
        $this->assertSame('CAP-1', $payment->provider_reference);
        $this->assertSame('CAP-1', $payment->paypalCaptureId());

        // Reserved units have left the shelf for real.
        $after = ProductVariant::find($variantId);
        $this->assertSame($before->stock_qty - 2, $after->stock_qty);
        $this->assertSame(0, $after->reserved_qty);
    }

    /**
     * THE test. PayPal saying COMPLETED is not evidence that the right amount
     * was paid, and an underpaid order must not be fulfilled.
     */
    public function test_a_capture_for_the_wrong_amount_is_refused(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        Http::fake($this->fakeToken() + [
            '*/v2/checkout/orders/PP-ORDER-1/capture' => Http::response($this->captureBody('1.00')),
        ]);

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
            'email' => 'dana@example.test',
        ])->assertStatus(422);

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    /** Paying in the wrong currency is the same class of problem. */
    public function test_a_capture_in_another_currency_is_refused(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        Http::fake($this->fakeToken() + [
            '*/v2/checkout/orders/PP-ORDER-1/capture' => Http::response(
                $this->captureBody($this->decimal($order->grand_total_cents), 'USD')
            ),
        ]);

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
            'email' => 'dana@example.test',
        ])->assertStatus(422);

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    /**
     * A PayPal order id the caller saw elsewhere must not be capturable against
     * this order — otherwise a cheap order's approval settles an expensive one.
     */
    public function test_a_paypal_order_id_we_did_not_issue_for_this_order_is_refused(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        Http::fake($this->fakeToken());

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-SOMEONE-ELSES',
            'email' => 'dana@example.test',
        ])->assertStatus(422);

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);

        // Nothing was captured: the guard runs before PayPal is contacted.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/capture'));
    }

    /** Rule 3: the browser and the webhook both arriving must not pay twice. */
    public function test_capturing_twice_is_idempotent(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();
        $variantId = $order->items->first()->product_variant_id;
        $before = ProductVariant::find($variantId)->stock_qty;

        Http::fake($this->fakeToken() + [
            '*/v2/checkout/orders/PP-ORDER-1/capture' => Http::response(
                $this->captureBody($this->decimal($order->grand_total_cents))
            ),
        ]);

        $payload = ['paypal_order_id' => 'PP-ORDER-1', 'email' => 'dana@example.test'];

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", $payload)->assertOk();
        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", $payload)->assertOk();

        $this->assertSame(1, $order->refunds()->count() + $order->payments()->count());
        $this->assertSame($before - 2, ProductVariant::find($variantId)->stock_qty);
        $this->assertSame(1, $order->fresh()->statusHistory()->where('note', 'Payment received.')->count());
    }

    /** Ownership is proved, because order numbers are sequential and guessable. */
    public function test_a_stranger_cannot_capture_somebody_elses_order(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        Http::fake($this->fakeToken());

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
            'email' => 'attacker@example.test',
        ])->assertNotFound();

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
        ])->assertNotFound();

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    /** Rule 4: a cancelled order must not become payable from a stale window. */
    public function test_a_cancelled_order_cannot_be_captured(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        app(OrderService::class)->cancel($order, 'Test.');

        Http::fake($this->fakeToken());

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/capture", [
            'paypal_order_id' => 'PP-ORDER-1',
            'email' => 'dana@example.test',
        ])->assertStatus(422);

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    // --------------------------------------------------------------- webhook

    /** An unverifiable notification is worth nothing — anyone could post it. */
    public function test_the_webhook_refuses_deliveries_when_no_webhook_id_is_configured(): void
    {
        $this->postJson('/api/v1/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
            ->assertStatus(503);
    }

    public function test_the_webhook_refuses_a_delivery_that_fails_verification(): void
    {
        config(['services.paypal.webhook_id' => 'WH-TEST']);

        Http::fake($this->fakeToken() + [
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $this->postJson('/api/v1/webhooks/paypal', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-1', 'custom_id' => 'BSD-10001', 'status' => 'COMPLETED'],
        ])->assertStatus(400);
    }

    /**
     * The safety net: the customer approved and then closed the tab, so the
     * browser never captured. The webhook confirms the order instead — with the
     * same amount check, because a webhook body is PayPal's assertion, not proof.
     */
    public function test_a_verified_capture_webhook_confirms_the_order(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        config(['services.paypal.webhook_id' => 'WH-TEST']);

        Http::fake($this->fakeToken() + [
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $this->postJson('/api/v1/webhooks/paypal', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-WEBHOOK',
                'status' => 'COMPLETED',
                'custom_id' => $order->order_number,
                'amount' => ['currency_code' => 'CAD', 'value' => $this->decimal($order->grand_total_cents)],
            ],
        ])->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertSame('CAP-WEBHOOK', $order->payments()->first()->paypalCaptureId());
    }

    public function test_a_webhook_capture_for_the_wrong_amount_does_not_confirm_the_order(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        config(['services.paypal.webhook_id' => 'WH-TEST']);

        Http::fake($this->fakeToken() + [
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        // 200 on purpose: the delivery was genuine and retrying it will not
        // help. The mismatch is logged for a human instead.
        $this->postJson('/api/v1/webhooks/paypal', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-WEBHOOK',
                'status' => 'COMPLETED',
                'custom_id' => $order->order_number,
                'amount' => ['currency_code' => 'CAD', 'value' => '1.00'],
            ],
        ])->assertOk();

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    // ---------------------------------------------------------------- tokens

    /** Minting a token per call is slow and a way to get rate-limited. */
    public function test_the_access_token_is_reused_across_calls(): void
    {
        Http::fake($this->fakeToken() + $this->fakeCreate());

        $order = $this->placePayPalOrder();

        $this->postJson("/api/v1/orders/{$order->order_number}/paypal/create", [
            'email' => 'dana@example.test',
        ])->assertOk();

        Http::assertSentCount(3); // one token, two order creations
    }
}
