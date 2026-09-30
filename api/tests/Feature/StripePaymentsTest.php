<?php

namespace Tests\Feature;

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
use App\Services\Payments\StripeGateway;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;
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

    // ------------------------------------------------------------- helpers

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
            'latest_charge' => [
                'id' => 'ch_test',
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
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

        $charge = [
            'id' => 'ch_test',
            'object' => 'charge',
            'payment_intent' => $order->payments()->first()->provider_reference,
            'refunds' => ['data' => [['id' => 're_dash_1', 'amount' => 1000]]],
        ];

        $this->webhook('charge.refunded', $charge)->assertOk();
        $this->webhook('charge.refunded', $charge)->assertOk();

        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $order->fresh()->payment_status);
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
