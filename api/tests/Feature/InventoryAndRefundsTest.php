<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Manual stock adjustment (§2, §9) and refund recording (§9).
 *
 * Both write to a ledger as well as a column, and in both cases it is the
 * ledger that has to stay trustworthy — a balance nobody can explain is worse
 * than a balance that is merely wrong.
 */
class InventoryAndRefundsTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    protected function variant(int $stock = 20, int $reserved = 0): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => 5000]))
            ->create(['stock_qty' => $stock, 'reserved_qty' => $reserved]);
    }

    protected function paidOrder(): Order
    {
        $variant = $this->variant();

        $order = Order::create([
            'order_number' => 'BSD-'.random_int(10000, 99999),
            'email' => 'dana@clinic.ca',
            'status' => Order::STATUS_PENDING_PAYMENT,
            'payment_status' => Order::PAYMENT_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'fulfillment_type' => Order::TYPE_SHIP,
            'subtotal_cents' => 10000,
            'discount_cents' => 0,
            'shipping_cents' => 0,
            'tax_cents' => 0,
            'grand_total_cents' => 10000,
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

        $order->payments()->create([
            'provider' => Payment::PROVIDER_ETRANSFER,
            'status' => Payment::STATUS_PENDING,
            'amount_cents' => 10000,
            'currency' => 'CAD',
        ]);

        $variant->increment('reserved_qty', 2);

        return app(OrderService::class)->markPaid($order->fresh('items'), null, $this->admin());
    }

    // ---- inventory -------------------------------------------------------

    public function test_adding_stock_writes_a_movement(): void
    {
        $variant = $this->variant(20);

        app(InventoryService::class)->adjust($variant, 12, InventoryMovement::REASON_PURCHASE, 'Delivery');

        $this->assertSame(32, $variant->fresh()->stock_qty);

        $movement = InventoryMovement::where('product_variant_id', $variant->id)->latest('id')->first();
        $this->assertSame(12, $movement->delta);
        $this->assertSame(32, $movement->balance_after);
        $this->assertSame('Delivery', $movement->note);
    }

    public function test_removing_stock_writes_a_negative_movement(): void
    {
        $variant = $this->variant(20);

        app(InventoryService::class)->adjust($variant, -5);

        $this->assertSame(15, $variant->fresh()->stock_qty);
        $this->assertSame(-5, InventoryMovement::latest('id')->first()->delta);
    }

    public function test_stock_cannot_go_negative(): void
    {
        $variant = $this->variant(3);

        $this->expectException(RuntimeException::class);

        app(InventoryService::class)->adjust($variant, -4);
    }

    /** The one adjustment that is never a correction — it oversells a live checkout. */
    public function test_stock_cannot_be_cut_below_what_is_reserved(): void
    {
        $variant = $this->variant(10, reserved: 6);

        try {
            app(InventoryService::class)->adjust($variant, -6);
            $this->fail('Expected the adjustment to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        $this->assertSame(10, $variant->fresh()->stock_qty);
    }

    public function test_a_refused_adjustment_writes_no_movement(): void
    {
        $variant = $this->variant(3);

        try {
            app(InventoryService::class)->adjust($variant, -99);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, InventoryMovement::where('product_variant_id', $variant->id)->count());
    }

    public function test_setting_an_absolute_count_records_the_difference(): void
    {
        $variant = $this->variant(20);

        app(InventoryService::class)->setTo($variant, 17, 'Stock take');

        $this->assertSame(17, $variant->fresh()->stock_qty);

        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(-3, $movement->delta);
        $this->assertSame(InventoryMovement::REASON_CORRECTION, $movement->reason);
    }

    // ---- refunds ---------------------------------------------------------

    public function test_a_partial_refund_leaves_the_order_partially_refunded(): void
    {
        $order = $this->paidOrder();

        app(OrderService::class)->refund($order, 2500, 'One item returned', restock: false);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $order->payment_status);
        $this->assertSame(2500, $order->totalRefundedCents());
        $this->assertSame(7500, $order->outstandingRefundableCents());
    }

    /**
     * Money going back is not money still owed. A partly refunded order used
     * to read "Pending Payment" to the customer and to admin alike, because
     * anything that was not exactly `paid` counted as unpaid.
     */
    public function test_a_partial_refund_does_not_send_the_order_back_to_pending_payment(): void
    {
        $order = $this->paidOrder();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);

        app(OrderService::class)->refund($order, 2500, 'One item returned', restock: false);

        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);

        // And it goes on moving through fulfilment under its own name.
        app(OrderService::class)->transitionFulfillment($order, Order::FULFILLMENT_SHIPPED);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_PARTIALLY_REFUNDED, $order->fresh()->payment_status);
    }

    public function test_refunding_the_full_total_marks_the_order_refunded(): void
    {
        $order = $this->paidOrder();

        app(OrderService::class)->refund($order, 10000, 'Returned', restock: false);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame(0, $order->outstandingRefundableCents());
    }

    /** Several partials that each look reasonable must not sum past the total. */
    public function test_refunds_cannot_exceed_the_order_total(): void
    {
        $order = $this->paidOrder();

        app(OrderService::class)->refund($order, 6000, restock: false);

        $this->expectException(RuntimeException::class);

        app(OrderService::class)->refund($order->fresh(), 5000, restock: false);
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $order = $this->paidOrder();
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);

        $this->expectException(RuntimeException::class);

        app(OrderService::class)->refund($order, 1000);
    }

    public function test_refunding_with_restock_returns_the_goods(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        // markPaid committed the sale, so 2 of the opening 20 are gone.
        $this->assertSame(18, $variant->stock_qty);

        app(OrderService::class)->refund($order, 10000, 'Returned', restock: true);

        $this->assertSame(20, $variant->fresh()->stock_qty);
        $this->assertSame(
            InventoryMovement::REASON_REFUND_RESTOCK,
            InventoryMovement::latest('id')->first()->reason
        );
    }

    public function test_refunding_without_restock_leaves_stock_alone(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        app(OrderService::class)->refund($order, 10000, 'Damaged', restock: false);

        $this->assertSame(18, $variant->fresh()->stock_qty);
    }

    public function test_a_refund_is_recorded_against_the_payment_and_actor(): void
    {
        $order = $this->paidOrder();
        $admin = $this->admin();

        $refund = app(OrderService::class)->refund(
            $order, 2500, 'Goodwill', restock: false, providerReference: 're_123', actor: $admin,
        );

        $this->assertSame('re_123', $refund->provider_reference);
        $this->assertSame($admin->id, $refund->created_by);
        $this->assertNotNull($refund->payment_id);
    }

    // ---- cancelling after a refund ---------------------------------------

    /**
     * An order some of whose money had gone back was cancelled as though it had
     * never been paid: the goods it sold stayed off the shelf, and the "hold"
     * it gave up instead was one belonging to somebody else's checkout.
     */
    public function test_cancelling_a_partly_refunded_order_returns_what_it_sold(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        // Another customer is part-way through checkout for the same item.
        $variant->increment('reserved_qty', 3);

        app(OrderService::class)->refund($order, 2500, 'Goodwill', restock: false);
        app(OrderService::class)->cancel($order->fresh());

        $variant->refresh();
        $this->assertSame(20, $variant->stock_qty, 'The two units this order sold should be back.');
        $this->assertSame(3, $variant->reserved_qty, 'The other checkout keeps its hold.');
    }

    /** The same for an order refunded in full and then cancelled. */
    public function test_cancelling_a_fully_refunded_order_returns_what_it_sold(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);
        $variant->increment('reserved_qty', 3);

        app(OrderService::class)->refund($order, 10000, 'Damaged in the warehouse', restock: false);
        app(OrderService::class)->cancel($order->fresh());

        $variant->refresh();
        $this->assertSame(20, $variant->stock_qty);
        $this->assertSame(3, $variant->reserved_qty);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    /** A refund that already put the goods back must not have them put back again. */
    public function test_cancelling_after_a_refund_that_restocked_does_not_restock_twice(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        app(OrderService::class)->refund($order, 2500, 'One item returned', restock: true);
        $this->assertSame(20, $variant->fresh()->stock_qty);

        app(OrderService::class)->cancel($order->fresh());

        $this->assertSame(20, $variant->fresh()->stock_qty);
    }

    /**
     * The ordinary way to unwind a sale: cancel it, then give the money back.
     * Cancelling returns the goods, and the refund form's "Return items to
     * stock" is ticked by default — which returned them a second time.
     */
    public function test_refunding_a_cancelled_order_does_not_restock_what_cancelling_returned(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        app(OrderService::class)->cancel($order);
        $this->assertSame(20, $variant->fresh()->stock_qty);

        $refund = app(OrderService::class)->refund($order->fresh(), 10000, 'Cancelled', restock: true);

        $this->assertSame(20, $variant->fresh()->stock_qty);
        $this->assertFalse((bool) $refund->restock, 'The ledger should not claim a restock that did not happen.');
    }

    /** Two refunds on one order, both asked to restock: the goods come back once. */
    public function test_a_second_refund_does_not_restock_again(): void
    {
        $order = $this->paidOrder();
        $variant = ProductVariant::find($order->items->first()->product_variant_id);

        app(OrderService::class)->refund($order, 2500, 'First', restock: true);
        app(OrderService::class)->refund($order->fresh(), 7500, 'Second', restock: true);

        $this->assertSame(20, $variant->fresh()->stock_qty);
    }
}
