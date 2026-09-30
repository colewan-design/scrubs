<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout: pricing, tax, shipping, stock and the order record (§4–§7).
 *
 * The assertions that matter most are the ones proving the order does not
 * simply believe what the client posted — prices and shipping costs are
 * re-derived server-side, and stock cannot go negative.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpCanada(): void
    {
        TaxRate::create([
            'province' => 'ON', 'tax_type' => 'HST', 'applies_to' => 'goods',
            'rate_bps' => 1300, 'is_active' => true,
        ]);
        TaxRate::create([
            'province' => 'ON', 'tax_type' => 'HST', 'applies_to' => 'shipping',
            'rate_bps' => 1300, 'is_active' => true,
        ]);

        $zone = ShippingZone::create([
            'name' => 'Canada', 'provinces' => [], 'position' => 0, 'is_active' => true,
        ]);

        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'name' => 'Standard', 'rate_cents' => 1500,
            'is_free' => false, 'position' => 0, 'is_active' => true,
            'delivery_days_min' => 3, 'delivery_days_max' => 7,
        ]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'name' => 'Express', 'rate_cents' => 2900,
            'is_free' => false, 'position' => 1, 'is_active' => true,
        ]);
    }

    protected function variant(int $priceCents = 5000, int $stock = 50): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => $priceCents]))
            ->create(['stock_qty' => $stock, 'reserved_qty' => 0]);
    }

    /**
     * A guest cart lives behind an opaque token, so every call in a checkout
     * flow has to carry it — exactly as the browser does.
     */
    protected ?string $cartToken = null;

    /** Checkout needs an account, so unless a test names one, this customer shops. */
    protected ?User $shopper = null;

    protected function shopper(): User
    {
        return $this->shopper ??= User::factory()->create();
    }

    protected function cartHeaders(): array
    {
        return $this->cartToken ? ['X-Cart-Token' => $this->cartToken] : [];
    }

    protected function addToCart(ProductVariant $variant, int $qty, ?User $user = null): void
    {
        $response = $this->actingAs($user ?? $this->shopper())->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => $qty]);

        $this->cartToken = $response->json('cart_token') ?: $this->cartToken;
    }

    protected function checkoutQuote(array $payload = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->shopper())->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', $payload ?: ['province' => 'ON']);
    }

    protected function placeOrder(array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->shopper())->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', $payload);
    }

    protected function address(): array
    {
        return [
            'first_name' => 'Dana', 'last_name' => 'Reid',
            'line1' => '10 Queen St W', 'city' => 'Toronto',
            'province' => 'ON', 'postal_code' => 'M5H 2N2', 'country' => 'CA',
        ];
    }

    /**
     * §6 — a receipt that charges GST/HST has to carry the seller's
     * registration number, and it must be the number in force when the money
     * changed hands, not whatever the setting says at render time.
     */
    public function test_the_tax_registration_number_is_frozen_onto_the_receipt(): void
    {
        $this->setUpCanada();
        app(Settings::class)->set('tax.gst_number', '123456789 RT0001');

        $variant = $this->variant(5000);
        $this->addToCart($variant, 2);
        $quote = $this->checkoutQuote();

        $number = $this->placeOrder([
            'email' => 'dana@example.test',
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ])->assertCreated()->json('order.order_number');

        $this->assertSame('123456789 RT0001', Order::where('order_number', $number)->value('tax_registration'));

        // Correcting the setting afterwards must not rewrite the receipt.
        app(Settings::class)->set('tax.gst_number', '999999999 RT0002');

        $this->getJson("/api/v1/orders/{$number}?email=dana@example.test")
            ->assertOk()
            ->assertJsonPath('order.tax_registration', '123456789 RT0001');
    }

    /** An unregistered business must not have a number invented for its receipts. */
    public function test_no_registration_number_is_shown_when_the_business_has_none(): void
    {
        $this->setUpCanada();
        app(Settings::class)->set('tax.gst_number', '');

        $variant = $this->variant(5000);
        $this->addToCart($variant, 1);
        $quote = $this->checkoutQuote();

        $number = $this->placeOrder([
            'email' => 'dana@example.test',
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ])->assertCreated()->json('order.order_number');

        // Registering later must not backdate a number onto February's receipts.
        app(Settings::class)->set('tax.gst_number', '123456789 RT0001');

        $this->getJson("/api/v1/orders/{$number}?email=dana@example.test")
            ->assertOk()
            ->assertJsonPath('order.tax_registration', null);
    }

    /** The rate the shopper is shown must be the rate the order is charged. */
    public function test_quote_returns_shipping_options_and_itemised_tax(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000);

        $this->addToCart($variant, 2);

        $response = $this->checkoutQuote(['province' => 'ON', 'postal_code' => 'M5H 2N2'])->assertOk();

        $response->assertJsonPath('shipping_options.0.name', 'Standard');
        $response->assertJsonPath('shipping_options.0.cost.cents', 1500);
        $response->assertJsonPath('shipping_options.0.delivery_estimate', '3–7 business days');

        // $100 goods + $15 shipping, both taxed at 13% = $14.95.
        $response->assertJsonPath('tax.lines.0.label', 'HST 13%');
        $response->assertJsonPath('tax.total_cents', 1495);
        $response->assertJsonPath('grand_total.cents', 10000 + 1500 + 1495);
    }

    public function test_order_is_placed_with_snapshotted_totals_and_reserved_stock(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000, 50);

        $this->addToCart($variant, 2);

        $quote = $this->checkoutQuote()->assertOk();
        $option = $quote->json('shipping_options.0.code');

        $response = $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'shipping_option' => $option,
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $response->assertJsonPath('order.status', Order::STATUS_PENDING_PAYMENT);
        $response->assertJsonPath('order.status_label', 'Pending Payment');
        $response->assertJsonPath('order.totals.shipping.cents', 1500);
        $response->assertJsonPath('order.totals.tax.cents', 1495);
        $response->assertJsonPath('order.totals.grand_total.cents', 12995);
        $response->assertJsonPath('order.taxes.0.label', 'HST 13%');
        $response->assertJsonPath('order.shipping_address.province', 'ON');

        // Readable, sequential, and never the database id.
        $this->assertMatchesRegularExpression('/^BSD-\d+$/', $response->json('order.order_number'));

        // Stock is HELD, not yet decremented: nothing has been paid for.
        $variant->refresh();
        $this->assertSame(50, $variant->stock_qty);
        $this->assertSame(2, $variant->reserved_qty);

        // The basket is emptied so the customer cannot buy it twice.
        $this->withHeaders($this->cartHeaders())->getJson('/api/v1/cart')
            ->assertJsonPath('item_count', 0);
    }

    /** Rule 1: a posted shipping choice is re-priced, never trusted. */
    public function test_checkout_rejects_an_unknown_shipping_option(): void
    {
        $this->setUpCanada();
        $variant = $this->variant();

        $this->addToCart($variant, 1);

        $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'shipping_option' => 'table:999999',
            'shipping_address' => $this->address(),
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_cannot_oversell(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000, 3);

        $this->addToCart($variant, 3);

        // Someone else takes the last of the stock between cart and checkout.
        $variant->update(['reserved_qty' => 3]);

        $quote = $this->checkoutQuote();

        $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(3, $variant->fresh()->stock_qty);
    }

    /** Free shipping must use the same threshold the cart advertises. */
    public function test_free_shipping_threshold_applies_to_the_cheapest_option_only(): void
    {
        $this->setUpCanada();
        app(Settings::class)->set('shipping.free_threshold_cents', 60000);

        $variant = $this->variant(5000, 100);
        $this->addToCart($variant, 13); // $650

        $response = $this->checkoutQuote()->assertOk();

        $options = collect($response->json('shipping_options'))->keyBy('name');
        $this->assertSame(0, $options['Standard']['cost']['cents']);
        $this->assertTrue($options['Standard']['free_threshold_applied']);
        $this->assertSame(2900, $options['Express']['cost']['cents']);
    }

    public function test_pickup_skips_shipping_entirely(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000);

        $this->addToCart($variant, 2);

        $response = $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'fulfillment_type' => Order::TYPE_PICKUP,
        ])->assertCreated();

        $response->assertJsonPath('order.fulfillment_type', Order::TYPE_PICKUP);
        $response->assertJsonPath('order.totals.shipping.cents', 0);
        $response->assertJsonPath('order.shipping_address', null);
        // Still taxed — at the store's own province.
        $response->assertJsonPath('order.totals.tax.cents', 1300);
    }

    /** Payment is what moves stock off the shelf, and it writes a ledger row. */
    public function test_marking_paid_decrements_stock_and_writes_the_ledger(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000, 50);

        $this->addToCart($variant, 2);
        $quote = $this->checkoutQuote();
        $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::first();
        app(OrderService::class)->markPaid($order);

        $variant->refresh();
        $this->assertSame(48, $variant->stock_qty);
        $this->assertSame(0, $variant->reserved_qty);

        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);

        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'delta' => -2,
            'balance_after' => 48,
            'reason' => 'purchase',
        ]);
    }

    public function test_cancelling_an_unpaid_order_releases_the_hold_without_touching_stock(): void
    {
        $this->setUpCanada();
        $variant = $this->variant(5000, 10);
        $user = User::factory()->create();

        $this->addToCart($variant, 4, $user);
        $quote = $this->checkoutQuote(user: $user);
        $this->placeOrder([
            'email' => $user->email,
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ], $user)->assertCreated();

        $order = Order::first();
        $this->assertSame(4, $variant->fresh()->reserved_qty);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->order_number}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_CANCELLED);

        $variant->refresh();
        $this->assertSame(10, $variant->stock_qty);
        $this->assertSame(0, $variant->reserved_qty);
    }

    /** Order numbers are guessable, so ownership is always proved. */
    public function test_an_order_is_not_readable_without_proving_ownership(): void
    {
        $this->setUpCanada();
        $variant = $this->variant();

        $this->addToCart($variant, 1);
        $quote = $this->checkoutQuote();
        $this->placeOrder([
            'email' => 'dana@clinic.ca',
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::first();

        // Everything below is asked by someone who is not signed in as the owner.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/orders/{$order->order_number}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->order_number}?email=someone@else.ca")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->order_number}?email=dana@clinic.ca")->assertOk();
    }

    /** Checking out needs an account — a signed-out shopper is sent to sign in. */
    public function test_a_signed_out_shopper_cannot_check_out(): void
    {
        $this->setUpCanada();

        $response = $this->postJson('/api/v1/cart/items', ['variant_id' => $this->variant()->id, 'qty' => 1])
            ->assertSuccessful();
        $this->cartToken = $response->json('cart_token');

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', ['province' => 'ON'])
            ->assertUnauthorized();

        $this->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@clinic.ca',
                'shipping_option' => 'standard',
                'shipping_address' => $this->address(),
            ])
            ->assertUnauthorized();

        $this->assertSame(0, Order::count());
    }

    /** Wholesale pricing must survive into the order, not evaporate at checkout. */
    public function test_a_member_order_records_the_tier_and_the_discount(): void
    {
        $this->setUpCanada();
        PricingTier::factory()->create([
            'name' => 'Tier 1', 'slug' => 'tier-1',
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 3000,
            'is_active' => true,
        ]);

        $variant = $this->variant(5000, 100);
        $user = User::factory()->create();

        $this->addToCart($variant, 5, $user);
        $quote = $this->checkoutQuote(user: $user);

        $response = $this->placeOrder([
            'email' => $user->email,
            'shipping_option' => $quote->json('shipping_options.0.code'),
            'shipping_address' => $this->address(),
        ], $user)->assertCreated();

        // $250 retail, 30% off = $175 charged, $75 saved.
        $response->assertJsonPath('order.totals.subtotal.cents', 25000);
        $response->assertJsonPath('order.totals.discount.cents', 7500);
        $response->assertJsonPath('order.pricing_tier_name', 'Tier 1');
        $response->assertJsonPath('order.items.0.unit_price.cents', 3500);
    }
}
