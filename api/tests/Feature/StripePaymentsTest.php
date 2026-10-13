<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Payments\GatewayRefund;
use App\Services\Payments\StripeGateway;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeGateway;
use Tests\Support\FakeStripeTransport;
use Tests\TestCase;

/**
 * Card payment, end to end (§4).
 *
 * WHAT THESE TESTS ARE ACTUALLY GUARDING
 *
 * Almost every assertion below is about one question: *what is allowed to mark
 * an order paid?* The answer has to be "a signed webhook from Stripe reporting
 * the full amount, against an order that is still open" — and nothing else.
 * Each test takes away one part of that sentence and proves the order stays
 * unpaid and the stock stays where it was.
 *
 * The signature check is exercised for real rather than stubbed: these tests
 * compute genuine HMACs, so a change that weakened verification would fail here
 * instead of passing against an agreeable mock.
 */
class StripePaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected FakeStripeGateway $stripe;

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);

        app(Settings::class)->set('payments.card_enabled', '1');

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

    protected function tearDown(): void
    {
        // The SDK keeps its transport in a static; hand it back the real one.
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    /**
     * Swap the fake gateway for the real one, talking to a pretend Stripe.
     *
     * For the few tests that are about the gateway's own decisions. Everything
     * else here is about what happens around it and keeps the fake.
     */
    protected function realGateway(): FakeStripeTransport
    {
        $transport = new FakeStripeTransport;

        ApiRequestor::setHttpClient($transport);
        $this->app->instance(StripeGateway::class, new StripeGateway);

        return $transport;
    }

    protected ?string $cartToken = null;

    protected ?User $shopper = null;

    /**
     * Checkout requires an account, so every order below is placed by one.
     * Reused across a test so the same customer keeps the same cart.
     */
    protected function shopper(): User
    {
        return $this->shopper ??= User::factory()->create(['email' => 'dana@example.com']);
    }

    protected function variant(int $priceCents = 5000, int $stock = 10): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => $priceCents]))
            ->create(['stock_qty' => $stock, 'reserved_qty' => 0]);
    }

    protected function addToCart(ProductVariant $variant, int $qty = 1): void
    {
        $response = $this->withHeaders($this->cartToken ? ['X-Cart-Token' => $this->cartToken] : [])
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => $qty]);

        $this->cartToken = $response->json('cart_token') ?: $this->cartToken;
    }

    protected function placeCardOrder(ProductVariant $variant, int $qty = 1): array
    {
        $this->addToCart($variant, $qty);

        $quote = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $response = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.com',
                'payment_method' => 'stripe',
                'shipping_option' => $quote->json('selected_shipping_option'),
                'shipping_address' => [
                    'first_name' => 'Dana', 'last_name' => 'Reid',
                    'line1' => '10 Queen St W', 'city' => 'Toronto',
                    'province' => 'ON', 'postal_code' => 'M5H 2N2', 'country' => 'CA',
                ],
            ]);

        $response->assertCreated();

        return [$response, Order::where('order_number', $response->json('order.order_number'))->firstOrFail()];
    }

    /** A genuinely signed Stripe webhook, computed the way Stripe computes it. */
    protected function webhook(string $type, array $object, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
    }

    protected function succeededIntent(Order $order, ?int $amount = null): array
    {
        return [
            'id' => $order->payments()->first()->provider_reference,
            'object' => 'payment_intent',
            'amount' => $order->grand_total_cents,
            'amount_received' => $amount ?? $order->grand_total_cents,
            'payment_method_types' => ['card'],
            // An id, which is all Stripe has sent here since API version
            // 2022-11-15. This fixture used to carry the whole charge, and the
            // card details never being saved in production hid behind it.
            'latest_charge' => 'ch_test',
        ];
    }

    /**
     * `charge.refunded` as Stripe sends it: the charge, and no list of its
     * refunds. The old fixture included that list, which is how the handler
     * went on reading something that has not been sent for years.
     */
    protected function refundedCharge(Order $order): array
    {
        return [
            'id' => 'ch_test',
            'object' => 'charge',
            'payment_intent' => $order->payments()->first()->provider_reference,
            'refunded' => false,
            'amount_refunded' => 1000,
        ];
    }

    // --------------------------------------------------------------- tests

    /**
     * Availability is credentials AND the admin switch — never either alone.
     * A key pasted into the environment must not start charging cards, and the
     * switch must not offer a card form with nothing behind it.
     */
    public function test_cards_are_offered_only_when_configured_and_switched_on(): void
    {
        $this->addToCart($this->variant());

        $offered = fn () => collect(
            $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
                ->postJson('/api/v1/checkout/quote', ['province' => 'ON'])
                ->json('payment_methods')
        )->pluck('code')->all();

        $this->assertContains('stripe', $offered());

        app(Settings::class)->set('payments.card_enabled', '0');
        $this->assertNotContains('stripe', $offered());

        app(Settings::class)->set('payments.card_enabled', '1');
        config(['services.stripe.secret' => '']);
        $this->assertNotContains('stripe', $offered());
    }

    /** The publishable key travels with the quote; the secret key never does. */
    public function test_the_quote_carries_the_publishable_key_and_nothing_else(): void
    {
        $this->addToCart($this->variant());

        $response = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $card = collect($response->json('payment_methods'))->firstWhere('code', 'stripe');

        $this->assertSame('pk_test_fake', $card['public_key']);
        $response->assertDontSee('sk_test_fake');
    }

    public function test_placing_a_card_order_opens_a_payment_and_reserves_stock(): void
    {
        $variant = $this->variant(5000, 10);
        [$response, $order] = $this->placeCardOrder($variant, 2);

        $response->assertJsonPath('payment.provider', 'stripe');
        $this->assertNotEmpty($response->json('payment.client_secret'));

        $payment = $order->payments()->first();
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame($order->grand_total_cents, $payment->amount_cents);

        // Held, not sold: an unpaid order reserves stock without decrementing.
        $variant->refresh();
        $this->assertSame(10, $variant->stock_qty);
        $this->assertSame(2, $variant->reserved_qty);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
    }

    /**
     * A provider outage must not lose the order. It is placed, the stock is
     * held, and the customer is handed a retry instead of an error.
     */
    public function test_an_unreachable_provider_still_places_the_order(): void
    {
        $this->stripe->failPrepare = true;

        $this->addToCart($this->variant());

        $quote = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $response = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.com',
                'payment_method' => 'stripe',
                'shipping_option' => $quote->json('selected_shipping_option'),
                'shipping_address' => [
                    'first_name' => 'Dana', 'last_name' => 'Reid',
                    'line1' => '10 Queen St W', 'city' => 'Toronto',
                    'province' => 'ON', 'postal_code' => 'M5H 2N2', 'country' => 'CA',
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('payment');
        $this->assertNotEmpty($response->json('payment_error'));
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * Stripe's advice to the integrator must not reach the customer as a 500.
     *
     * Run against the real gateway, because the failure lived in the seam the
     * fake removes: Stripe answers with a `Stripe-Notice` header ("you are
     * using an outdated API version"), stripe-php raises it as a warning, and
     * Laravel throws on warnings. The intent had been created at Stripe; the
     * order, already placed and holding stock, never learned its id.
     */
    public function test_a_stripe_notice_does_not_fail_the_checkout(): void
    {
        $stripe = $this->realGateway();
        $stripe->headers = ['Stripe-Notice' => 'You are using an outdated API version (2024-06-20).'];
        Log::spy();

        [$response, $order] = $this->placeCardOrder($this->variant());

        $response->assertJsonPath('payment.reference', FakeStripeTransport::INTENT);
        $response->assertJsonMissingPath('payment_error');

        $this->assertSame(FakeStripeTransport::INTENT, $order->payments()->first()->provider_reference);
        $this->assertSame(['POST /v1/payment_intents'], $stripe->requests);

        // Logged, not lost: it is still advice somebody has to act on.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'Stripe notice.'
                && str_contains($context['message'] ?? '', 'outdated API version'))
            ->once();
    }

    /** Coming back to an unfinished payment asks Stripe about it and opens nothing new. */
    public function test_reopening_an_unfinished_payment_reuses_its_intent(): void
    {
        $stripe = $this->realGateway();
        [, $order] = $this->placeCardOrder($this->variant());

        $stripe->requests = [];

        $this->actingAs($this->shopper())
            ->postJson("/api/v1/orders/{$order->order_number}/payment")
            ->assertOk()
            ->assertJsonPath('payment.reference', FakeStripeTransport::INTENT);

        $this->assertSame(['GET /v1/payment_intents/'.FakeStripeTransport::INTENT], $stripe->requests);
    }

    /**
     * The customer has paid and the webhook has not landed yet, so the order
     * still reads as unpaid. Asking to pay it must not produce a second card
     * form: the answer is that the money has arrived, flagged so the receipt
     * page waits for the webhook rather than showing an error.
     */
    public function test_a_payment_stripe_has_already_taken_is_not_reopened(): void
    {
        $stripe = $this->realGateway();
        [, $order] = $this->placeCardOrder($this->variant());

        $stripe->intentStatus = 'succeeded';
        $stripe->requests = [];

        $this->actingAs($this->shopper())
            ->postJson("/api/v1/orders/{$order->order_number}/payment")
            ->assertStatus(409)
            ->assertJsonPath('payment_received', true)
            ->assertJsonMissingPath('payment');

        // Asked what state the payment was in — and did not open another.
        $this->assertSame(['GET /v1/payment_intents/'.FakeStripeTransport::INTENT], $stripe->requests);

        // Still the webhook's job to say so: the order itself has not moved.
        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    public function test_a_signed_webhook_marks_the_order_paid_and_commits_stock(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))
            ->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);

        // The reservation has become a real decrement, with a ledger row.
        $variant->refresh();
        $this->assertSame(8, $variant->stock_qty);
        $this->assertSame(0, $variant->reserved_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'delta' => -2,
            'reason' => InventoryMovement::REASON_PURCHASE,
        ]);

        // Last four and brand only — never the number (§12).
        $payment = $order->payments()->first();
        $this->assertSame('visa', $payment->card_brand);
        $this->assertSame('4242', $payment->card_last_four);
    }

    /**
     * THE ONE THAT MATTERS MOST. An unsigned POST to the webhook URL is an
     * anonymous request to mark an order paid and release goods.
     */
    public function test_an_unsigned_or_wrongly_signed_webhook_cannot_mark_an_order_paid(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        // Signed with the wrong secret.
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order), 'whsec_wrong')
            ->assertStatus(400);

        // No signature header at all.
        $this->postJson('/api/v1/webhooks/stripe', [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => $this->succeededIntent($order)],
        ])->assertStatus(400);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);

        $variant->refresh();
        $this->assertSame(10, $variant->stock_qty);
        $this->assertSame(2, $variant->reserved_qty);
    }

    /** Stripe retries, and can deliver twice regardless. Stock must move once. */
    public function test_a_replayed_webhook_does_not_commit_stock_twice(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $variant->refresh();
        $this->assertSame(8, $variant->stock_qty);
        $this->assertSame(
            1,
            InventoryMovement::where('reason', InventoryMovement::REASON_PURCHASE)->count(),
        );
    }

    /**
     * The replay that arrives late. Stripe can deliver an event again long
     * after the first one — and by then the order may have been refunded. That
     * second delivery found an order that was "not paid", marked it paid again,
     * took its stock a second time and put it back in the fulfilment queue.
     */
    public function test_a_webhook_replayed_after_a_refund_does_not_revive_the_order(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $event = $this->succeededIntent($order);
        $this->webhook('payment_intent.succeeded', $event)->assertOk();

        app(OrderService::class)->refund(
            order: $order->refresh(),
            amountCents: $order->grand_total_cents,
            reason: 'Customer changed their mind.',
            restock: true,
            throughGateway: true,
        );

        $this->assertSame(10, $variant->fresh()->stock_qty);

        $this->webhook('payment_intent.succeeded', $event)->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $order->payments()->first()->status);

        $this->assertSame(10, $variant->fresh()->stock_qty);
        $this->assertSame(
            1,
            InventoryMovement::where('reason', InventoryMovement::REASON_PURCHASE)->count(),
        );
    }

    /** The same late replay against an order only part of which went back. */
    public function test_a_webhook_replayed_after_a_partial_refund_changes_nothing(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $event = $this->succeededIntent($order);
        $this->webhook('payment_intent.succeeded', $event)->assertOk();

        app(OrderService::class)->refund(
            order: $order->refresh(),
            amountCents: 2500,
            reason: 'Goodwill.',
            restock: false,
            throughGateway: true,
        );

        $this->webhook('payment_intent.succeeded', $event)->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(8, $variant->fresh()->stock_qty);
    }

    /** Paying less than the total is not paying for the order. */
    public function test_an_underpayment_is_refused_and_flagged_internally(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook(
            'payment_intent.succeeded',
            $this->succeededIntent($order, $order->grand_total_cents - 500),
        )->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);

        $variant->refresh();
        $this->assertSame(10, $variant->stock_qty);

        $this->assertTrue(
            $order->statusHistory()->where('is_internal', true)->exists(),
            'An underpayment should leave an internal note for an administrator.',
        );
    }

    /**
     * The race the reservation sweeper creates: money arrives for an order
     * whose stock has already gone back on the shelf. It must not ship, and it
     * must not be silently kept.
     */
    public function test_payment_after_cancellation_is_recorded_but_never_fulfilled(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        app(OrderService::class)->cancel($order, 'Reservation expired.');

        $variant->refresh();
        $this->assertSame(0, $variant->reserved_qty, 'Cancelling should release the hold.');

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotSame(Order::PAYMENT_PAID, $order->payment_status);

        // Stock stays returned — the goods were never allocated.
        $variant->refresh();
        $this->assertSame(10, $variant->stock_qty);

        // ...but the money is acknowledged, so it can be given back.
        $this->assertSame(Payment::STATUS_SUCCEEDED, $order->payments()->first()->status);
        $this->assertTrue($order->statusHistory()->where('is_internal', true)->exists());
    }

    /** Provider messages about money going wrong are not for the customer. */
    public function test_internal_notes_never_reach_the_customer_timeline(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook(
            'payment_intent.succeeded',
            $this->succeededIntent($order, $order->grand_total_cents - 500),
        )->assertOk();

        $response = $this->getJson(
            "/api/v1/orders/{$order->order_number}?email=".urlencode($order->email)
        )->assertOk();

        foreach ($response->json('order.timeline') as $entry) {
            $this->assertStringNotContainsString('needs review', (string) $entry['note']);
        }

        $response->assertDontSee('needs review');
    }

    public function test_refunding_a_card_order_sends_the_money_back_through_the_provider(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();
        $order->refresh();

        $refund = app(OrderService::class)->refund(
            order: $order,
            amountCents: $order->grand_total_cents,
            reason: 'Customer changed their mind.',
            restock: true,
            throughGateway: true,
        );

        // The provider was actually called, and its reference is what the
        // ledger recorded — that is what reconciles the two sides.
        $this->assertCount(1, $this->stripe->refunded);
        $this->assertSame('re_test_1', $refund->provider_reference);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
    }

    /** A refused refund must not leave a ledger row claiming it happened. */
    public function test_a_refused_refund_writes_nothing(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();
        $order->refresh();

        $this->stripe->failRefundWith = 'The refund was refused by Stripe.';

        try {
            app(OrderService::class)->refund(
                order: $order,
                amountCents: 1000,
                throughGateway: true,
            );
            $this->fail('A refused refund should throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    /** Refunds issued in the Stripe dashboard are reconciled, and only once. */
    public function test_a_dashboard_refund_is_recorded_once(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();
        $order->refresh();

        $this->stripe->refundedElsewhere = [new GatewayRefund('re_dash_1', 1000, 'succeeded')];

        $this->webhook('charge.refunded', $this->refundedCharge($order))->assertOk();
        $this->webhook('charge.refunded', $this->refundedCharge($order))->assertOk();

        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('refunds', ['provider_reference' => 're_dash_1', 'amount_cents' => 1000]);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $order->fresh()->payment_status);
    }

    /**
     * A refund issued from admin comes back as the same event. The request
     * that issued it writes the ledger row, so the webhook must leave it alone
     * — including in the moment before that row exists. Run against the real
     * gateway, because telling the two kinds of refund apart is its job.
     */
    public function test_only_refunds_made_outside_the_shop_are_recorded_from_the_webhook(): void
    {
        $stripe = $this->realGateway();
        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $stripe->answers['GET /v1/refunds'] = [
            'object' => 'list',
            'has_more' => false,
            'data' => [
                // Issued by this shop a moment ago; its ledger row is not written yet.
                ['id' => 're_ours', 'object' => 'refund', 'amount' => 2500, 'status' => 'succeeded',
                    'metadata' => ['order_id' => (string) $order->id]],
                // Issued by someone in the Stripe dashboard.
                ['id' => 're_dashboard', 'object' => 'refund', 'amount' => 1000, 'status' => 'succeeded',
                    'metadata' => []],
                // Tried in the dashboard, and refused by the bank.
                ['id' => 're_failed', 'object' => 'refund', 'amount' => 700, 'status' => 'failed',
                    'metadata' => []],
            ],
        ];

        $this->webhook('charge.refunded', $this->refundedCharge($order))->assertOk();

        $this->assertSame(['re_dashboard'], $order->refunds()->pluck('provider_reference')->all());
        $this->assertSame(1000, $order->fresh()->totalRefundedCents());
    }

    /**
     * Not being able to ask is not the same as there being nothing to record.
     * Money has moved that the ledger does not show, so somebody is told.
     */
    public function test_a_refund_stripe_cannot_be_asked_about_is_flagged_for_a_person(): void
    {
        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $this->stripe->failRefundLookup = true;

        $this->webhook('charge.refunded', $this->refundedCharge($order))->assertOk();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertTrue(
            $order->statusHistory()->where('is_internal', true)->where('note', 'like', '%Stripe dashboard%')->exists(),
        );
    }

    // ---------------------------------------------------------- card details

    /**
     * Stripe names the charge by id, so the card has to be asked for — and
     * asked for once, not again on every redelivery of the same event.
     */
    public function test_the_card_is_looked_up_once_and_only_brand_and_last_four_are_kept(): void
    {
        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $payment = $order->payments()->first();
        $this->assertSame('visa', $payment->card_brand);
        $this->assertSame('4242', $payment->card_last_four);
        $this->assertSame(1, $this->stripe->cardLookups);
    }

    /** "Which card?" must never be able to stand in the way of "is it paid?". */
    public function test_an_order_is_paid_even_when_the_card_cannot_be_looked_up(): void
    {
        $this->stripe->card = null;

        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertNull($order->payments()->first()->card_last_four);
    }

    /** The real gateway reads the card off the charge Stripe expands for it. */
    public function test_the_gateway_reads_the_card_from_the_expanded_charge(): void
    {
        $stripe = $this->realGateway();
        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);

        $stripe->intentStatus = 'succeeded';
        $stripe->intentExtras = ['latest_charge' => [
            'id' => 'ch_test',
            'object' => 'charge',
            'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '4444']],
        ]];

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $payment = $order->payments()->first();
        $this->assertSame('mastercard', $payment->card_brand);
        $this->assertSame('4444', $payment->card_last_four);
    }

    // ------------------------------------------- cancelling after a decline

    /**
     * A declined card leaves its payment open at Stripe for another attempt.
     * Cancelling the order used to release only payments still marked pending,
     * so after a decline the card form behind a cancelled order stayed live.
     */
    public function test_cancelling_after_a_declined_card_closes_the_payment_at_the_provider(): void
    {
        [, $order] = $this->placeCardOrder($this->variant(5000, 10), 2);
        $payment = $order->payments()->first();

        $this->webhook('payment_intent.payment_failed', [
            'id' => $payment->provider_reference,
            'object' => 'payment_intent',
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ])->assertOk();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);

        app(OrderService::class)->cancel($order->fresh());

        $this->assertSame([$payment->id], $this->stripe->abandoned);
    }

    /**
     * The payment that lands anyway — made in the same second the order was
     * cancelled. It is flagged for a refund, and the shop has to be able to
     * make one: through the provider, without returning stock that never left,
     * and without turning a cancelled order into a paid or refunded one.
     */
    public function test_a_payment_that_lands_on_a_cancelled_order_can_be_refunded(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 2);

        app(OrderService::class)->cancel($order, 'Reservation expired.');
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $order->refresh();
        $this->assertTrue($order->hasStrayPayment());

        $refund = app(OrderService::class)->refund(
            order: $order,
            amountCents: $order->grand_total_cents,
            reason: 'Paid after the order was cancelled.',
            restock: true,
            throughGateway: true,
        );

        $this->assertCount(1, $this->stripe->refunded);
        $this->assertFalse((bool) $refund->restock);
        $this->assertSame(10, $variant->fresh()->stock_qty);

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(Payment::STATUS_REFUNDED, $order->payments()->first()->status);
        $this->assertFalse($order->hasStrayPayment());

        // The same event delivered again does not bring the payment back.
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $this->assertSame(Payment::STATUS_REFUNDED, $order->payments()->first()->status);
        $this->assertSame(1, $order->statusHistory()->where('note', 'like', '%AFTER cancellation%')->count());
    }

    /**
     * And from where an administrator actually does it. The Refund action was
     * only ever offered on paid orders, so the note telling them to refund this
     * one pointed at a button that was not there.
     */
    public function test_the_admin_order_page_offers_the_refund_for_a_late_payment(): void
    {
        $variant = $this->variant(5000, 10);
        [, $late] = $this->placeCardOrder($variant, 2);
        app(OrderService::class)->cancel($late, 'Reservation expired.');
        $this->webhook('payment_intent.succeeded', $this->succeededIntent($late))->assertOk();

        // Cancelled too, but nobody ever paid for it: nothing to refund.
        $this->cartToken = null;
        [, $unpaid] = $this->placeCardOrder($variant, 1);
        app(OrderService::class)->cancel($unpaid, 'Reservation expired.');

        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        Livewire::test(ViewOrder::class, ['record' => $unpaid->getRouteKey()])
            ->assertActionHidden('refund');

        Livewire::test(ViewOrder::class, ['record' => $late->getRouteKey()])
            ->assertActionVisible('refund')
            ->callAction('refund', data: [
                'amount' => number_format($late->grand_total_cents / 100, 2, '.', ''),
                'reason' => 'Paid after cancellation.',
            ])
            ->assertHasNoActionErrors();

        $this->assertCount(1, $this->stripe->refunded);
        $this->assertSame($late->grand_total_cents, $this->stripe->refunded[0]['amount']);
        $this->assertSame(Payment::STATUS_REFUNDED, $late->payments()->first()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $late->fresh()->status);
        $this->assertSame(10, $variant->fresh()->stock_qty);
    }

    // ------------------------------------------------- reservation sweeper

    /**
     * The leak a card step introduces: a basket abandoned at the card form
     * holds stock nobody else can buy.
     */
    public function test_the_sweeper_releases_abandoned_card_orders(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 3);

        $variant->refresh();
        $this->assertSame(3, $variant->reserved_qty);

        // Still inside the window: nothing happens.
        $this->artisan('orders:release-abandoned')->assertExitCode(0);
        $this->assertSame(3, $variant->fresh()->reserved_qty);

        $order->update(['placed_at' => now()->subMinutes(60)]);

        $this->artisan('orders:release-abandoned')->assertExitCode(0);

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(0, $variant->fresh()->reserved_qty);
        $this->assertSame(10, $variant->fresh()->stock_qty);

        // The stranded intent is cancelled at the provider too.
        $this->assertNotEmpty($this->stripe->abandoned);
    }

    /**
     * e-Transfer orders are SUPPOSED to sit unpaid for days. Sweeping them on a
     * timer would cancel legitimate orders while the customer is queueing at
     * their bank.
     */
    public function test_the_sweeper_leaves_offline_orders_alone(): void
    {
        app(Settings::class)->set('payments.card_enabled', '0');
        app(Settings::class)->set('orders.etransfer_enabled', '1');

        $variant = $this->variant(5000, 10);
        $this->addToCart($variant, 2);

        $quote = $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON']);

        $this->actingAs($this->shopper())->withHeaders(['X-Cart-Token' => $this->cartToken])
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@example.com',
                'payment_method' => 'etransfer',
                'shipping_option' => $quote->json('selected_shipping_option'),
                'shipping_address' => [
                    'first_name' => 'Dana', 'last_name' => 'Reid',
                    'line1' => '10 Queen St W', 'city' => 'Toronto',
                    'province' => 'ON', 'postal_code' => 'M5H 2N2', 'country' => 'CA',
                ],
            ])->assertCreated();

        Order::query()->update(['placed_at' => now()->subDays(3)]);

        $this->artisan('orders:release-abandoned')->assertExitCode(0);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::first()->status);
        $this->assertSame(2, $variant->fresh()->reserved_qty);
    }

    // ------------------------------------------------------- retry endpoint

    public function test_a_retry_reuses_the_same_payment_and_proves_ownership(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 1);

        $originalReference = $order->payments()->first()->provider_reference;

        // Someone else who guesses the order number gets nothing — neither the
        // account nor the email matches, which is the whole of the proof.
        $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']))
            ->postJson("/api/v1/orders/{$order->order_number}/payment", [
                'email' => 'someone-else@example.com',
            ])->assertNotFound();

        $this->actingAs($this->shopper())
            ->postJson("/api/v1/orders/{$order->order_number}/payment", [
                'email' => $order->email,
            ])->assertOk()->assertJsonPath('payment.reference', $originalReference);

        // One payment, not two: a reload must not open a second authorisation.
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_a_settled_order_cannot_be_paid_again(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 1);

        $this->webhook('payment_intent.succeeded', $this->succeededIntent($order))->assertOk();

        $this->postJson("/api/v1/orders/{$order->order_number}/payment", [
            'email' => $order->email,
        ])->assertStatus(422);
    }

    /** A member's own order still works without the email proof. */
    public function test_a_signed_in_customer_can_retry_their_own_order(): void
    {
        $variant = $this->variant(5000, 10);
        [, $order] = $this->placeCardOrder($variant, 1);

        $this->actingAs($this->shopper())
            ->postJson("/api/v1/orders/{$order->order_number}/payment")
            ->assertOk();
    }
}
