<?php

namespace Tests\Feature;

use App\Models\Color;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The admin side of order management (§9).
 *
 * The panel is view-only on purpose, so what is worth testing is not the
 * rendering but the guard behind every action: OrderService refuses illegal
 * transitions rather than silently accepting them.
 */
class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    protected function order(string $type = Order::TYPE_SHIP): Order
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => 5000]))
            ->create(['stock_qty' => 20, 'reserved_qty' => 0]);

        $order = Order::create([
            'order_number' => 'BSD-'.random_int(10000, 99999),
            'email' => 'dana@clinic.ca',
            'status' => Order::STATUS_PENDING_PAYMENT,
            'payment_status' => Order::PAYMENT_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'fulfillment_type' => $type,
            'subtotal_cents' => 10000,
            'discount_cents' => 0,
            'shipping_cents' => 1500,
            'tax_cents' => 1495,
            'grand_total_cents' => 12995,
            'currency' => 'CAD',
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => 'Scrub Set',
            'variant_sku' => $variant->sku,
            'qty' => 2,
            'unit_retail_cents' => 5000,
            'unit_price_cents' => 5000,
            'line_discount_cents' => 0,
            'line_total_cents' => 10000,
        ]);

        $variant->increment('reserved_qty', 2);

        return $order->fresh('items');
    }

    public function test_the_admin_panel_lists_orders(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->get('/admin/orders')
            ->assertOk()
            ->assertSee($order->order_number);
    }

    /**
     * The list page was covered and the view page was not, which is how a
     * closure Filament could not resolve reached production. Filament injects
     * closure arguments by parameter name first and by type second, and a
     * builtin type-hint answers neither — `fn (string $s)` threw a
     * BindingResolutionException for every order opened. Rendering the page is
     * the only thing that catches it; nothing about the schema is checkable
     * statically.
     *
     * Transitioning first so the status-history repeatable has rows: its
     * entries are evaluated per row, so an empty history renders none of them.
     */
    public function test_the_admin_panel_renders_a_single_order(): void
    {
        $order = $this->order();
        app(OrderService::class)->markPaid($order, null, $this->admin());

        $this->actingAs($this->admin())
            ->get('/admin/orders/'.$order->getRouteKey())
            ->assertOk()
            ->assertSee($order->order_number)
            // markPaid lands on Processing rather than Paid for a shipping
            // order, so the label comes from the order itself instead of a
            // guessed constant.
            ->assertSee($order->refresh()->statusLabel());

        // The other formatter that was broken is fulfilment type. Asserting
        // 'Ship' would prove nothing — the "Shipping partner" heading contains
        // it whether the formatter ran or not. 'Local pickup' has one source.
        $pickup = $this->order(Order::TYPE_PICKUP);

        $this->actingAs($this->admin())
            ->get('/admin/orders/'.$pickup->getRouteKey())
            ->assertOk()
            ->assertSee('Local pickup');
    }

    /**
     * The two pieces of the order page that are logic rather than formatting.
     *
     * The swatch matters most: its hex is operator-entered free text and this
     * is the one place it is interpolated into markup, so the pattern check in
     * OrderInfolist::swatch() is the thing standing between the colours table
     * and a style attribute. It is also read live through the variant, which
     * is why a deleted variant has to leave the colour NAME — the snapshot —
     * intact behind it.
     */
    public function test_the_order_page_collapses_matching_addresses_and_degrades_the_swatch(): void
    {
        $color = Color::create(['name' => 'Sage', 'slug' => 'sage', 'hex' => '#4a6b5d']);
        $variant = ProductVariant::factory()
            ->for(Product::factory())
            ->create(['stock_qty' => 5, 'reserved_qty' => 0, 'color_id' => $color->id]);

        $order = $this->order();
        $order->items()->update(['product_variant_id' => $variant->id, 'color_name' => 'Sage']);

        foreach ([OrderAddress::TYPE_SHIPPING, OrderAddress::TYPE_BILLING] as $type) {
            $order->addresses()->create([
                'type' => $type, 'first_name' => 'Dana', 'last_name' => 'Reyes',
                'line1' => '14 Pine Ave', 'city' => 'Halifax', 'province' => 'NS',
                'postal_code' => 'B3H 1A1', 'country' => 'CA',
            ]);
        }

        $url = '/admin/orders/'.$order->getRouteKey();

        $this->actingAs($this->admin())->get($url)
            ->assertOk()
            ->assertSee('Same as shipping address')
            ->assertSee('bsd-swatch', escape: false)
            ->assertSee('#4a6b5d', escape: false);

        // Variant gone: the name survives, the dot does not.
        $variant->forceDelete();

        $this->actingAs($this->admin())->get($url)
            ->assertOk()
            ->assertSee('Sage')
            ->assertDontSee('bsd-swatch', escape: false);
    }

    public function test_a_non_admin_cannot_reach_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/orders')
            ->assertForbidden();
    }

    /** The whole payment flow depends on this: e-Transfer is settled by hand (§4). */
    public function test_marking_paid_moves_the_order_into_fulfilment(): void
    {
        $order = $this->order();

        app(OrderService::class)->markPaid($order, null, $this->admin());

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertNotNull($order->paid_at);

        // Rule 4: every transition writes history.
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_PROCESSING,
        ]);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $order = $this->order();

        // Unfulfilled cannot jump straight to shipped — payment comes first.
        $this->expectException(RuntimeException::class);
        app(OrderService::class)->transitionFulfillment($order, Order::FULFILLMENT_SHIPPED);
    }

    public function test_a_cancelled_order_cannot_be_revived(): void
    {
        $order = $this->order();
        app(OrderService::class)->cancel($order, 'Customer changed their mind.');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);

        $this->expectException(RuntimeException::class);
        app(OrderService::class)->transitionFulfillment($order->fresh(), Order::FULFILLMENT_PROCESSING);
    }

    /** A pickup order is collected, never shipped. */
    public function test_a_pickup_order_runs_through_ready_for_pickup(): void
    {
        $order = $this->order(Order::TYPE_PICKUP);
        $service = app(OrderService::class);

        $service->markPaid($order);
        $service->transitionFulfillment($order->fresh(), Order::FULFILLMENT_READY_FOR_PICKUP);

        $this->assertSame(Order::STATUS_READY_FOR_PICKUP, $order->fresh()->status);

        $service->transitionFulfillment($order->fresh(), Order::FULFILLMENT_COMPLETED);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    /** Cancelling a paid order puts the stock back, with a ledger row explaining it. */
    public function test_cancelling_a_paid_order_restocks(): void
    {
        $order = $this->order();
        $service = app(OrderService::class);

        $service->markPaid($order);
        $variant = $order->items->first()->variant;
        $this->assertSame(18, $variant->fresh()->stock_qty);

        $service->cancel($order->fresh(), 'Out of stock at the warehouse.');

        $this->assertSame(20, $variant->fresh()->stock_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'delta' => 2,
            'reason' => 'cancellation',
        ]);
    }
}
