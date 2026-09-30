<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Services\CartService;
use App\Services\Notifications\OrderNotifier;
use App\Services\Payments\PaymentService;
use App\Services\Pricing\PriceQuote;
use App\Services\Shipping\Destination;
use App\Services\Shipping\Parcel;
use App\Services\Shipping\ShippingOption;
use App\Services\Shipping\ShippingService;
use App\Services\Tax\TaxQuote;
use App\Services\Tax\TaxService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Order creation and the status machine (§7).
 *
 * FOUR RULES THIS CLASS MUST NEVER BREAK
 *
 *  1. Prices are re-quoted here, never carried over from the cart. A basket
 *     left open for two days must not be able to charge stale prices, and a
 *     client that posts its own totals must not be believed. The same is true
 *     of the shipping cost: the chosen option is looked up and re-priced, not
 *     read from the request.
 *
 *  2. Stock mutations hold a row lock. Without SELECT … FOR UPDATE, two
 *     simultaneous checkouts for the last unit both succeed — the classic
 *     oversell, and one that only appears under exactly the traffic a
 *     successful promotion produces.
 *
 *  3. `status` is derived from payment_status + fulfillment_status, never set
 *     by hand, so a shipped-then-refunded order is representable while the
 *     customer still sees one of the eight §7 names.
 *
 *  4. Every transition writes history. The customer timeline and the admin
 *     audit trail are the same rows; an untracked status change is a bug.
 */
class OrderService
{
    public function __construct(
        protected CartService $carts,
        protected TaxService $tax,
        protected ShippingService $shipping,
        protected Settings $settings,
        protected OrderNotifier $notifier,
        protected PaymentService $payments,
    ) {}

    /**
     * Turn a cart into an order.
     *
     * @param  array{
     *     email: string, phone?: ?string, customer_note?: ?string,
     *     fulfillment_type?: string, shipping_option?: ?string,
     *     shipping_address?: ?array, billing_address?: ?array,
     *     payment_method?: string
     * }  $input
     */
    public function place(Cart $cart, ?User $user, array $input): Order
    {
        $quote = $this->carts->quote($cart, $user);

        if ($quote->lines->isEmpty()) {
            throw new RuntimeException('Cannot place an order for an empty cart.');
        }

        $isPickup = ($input['fulfillment_type'] ?? Order::TYPE_SHIP) === Order::TYPE_PICKUP;
        $shippingAddress = $isPickup ? null : ($input['shipping_address'] ?? null);

        if (! $isPickup && ! $shippingAddress) {
            throw new RuntimeException('A shipping address is required for delivery orders.');
        }

        $destination = $isPickup
            ? new Destination($this->settings->string('pickup.province') ?: 'ON')
            : Destination::fromArray($shippingAddress);

        // Rule 1: the option is re-priced from the same service that offered it.
        $option = $this->resolveShippingOption($input, $isPickup, $quote, $destination);

        // Tax lands on what is actually charged — the discounted subtotal —
        // plus shipping where the province taxes freight.
        $taxQuote = $this->tax->calculate(
            $destination->province,
            $quote->subtotalCents,
            $option->costCents,
        );

        // Resolved against what is actually switched on, not against what the
        // client asked for — a request naming a method the store does not offer
        // must not create a payment row nobody can settle.
        $paymentMethod = $this->payments->resolveMethod($input['payment_method'] ?? null);

        $order = DB::transaction(function () use (
            $cart, $user, $input, $quote, $option, $taxQuote, $isPickup, $shippingAddress, $paymentMethod
        ) {
            $this->reserveStock($quote);

            $grandTotal = $quote->subtotalCents + $option->costCents + $taxQuote->totalCents();

            $order = Order::create([
                // Replaced immediately below; the column is unique and NOT NULL,
                // and the readable number is derived from the id.
                'order_number' => 'tmp-'.Str::random(20),
                'user_id' => $user?->id,
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'payment_status' => Order::PAYMENT_PENDING,
                'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
                'fulfillment_type' => $isPickup ? Order::TYPE_PICKUP : Order::TYPE_SHIP,
                'pricing_tier_id' => $quote->tier?->id,
                'pricing_tier_name' => $quote->tier?->name,
                'subtotal_cents' => $quote->retailSubtotalCents,
                'discount_cents' => $quote->discountCents,
                'shipping_cents' => $option->costCents,
                'tax_cents' => $taxQuote->totalCents(),
                'grand_total_cents' => $grandTotal,
                'currency' => 'CAD',
                // Frozen, not read live at render time (§6). See the migration.
                'tax_registration' => $this->settings->string('tax.gst_number'),
                'customer_note' => $input['customer_note'] ?? null,
                'placed_at' => now(),
            ]);

            $order->update(['order_number' => $this->orderNumberFor($order)]);

            $this->writeItems($order, $quote);
            $this->writeTaxes($order, $taxQuote);

            if ($shippingAddress) {
                $this->writeAddress($order, OrderAddress::TYPE_SHIPPING, $shippingAddress);
            }

            // Billing defaults to the shipping address — the overwhelmingly
            // common case, and one fewer form for the customer to fill in.
            $this->writeAddress(
                $order,
                OrderAddress::TYPE_BILLING,
                $input['billing_address'] ?? $shippingAddress ?? []
            );

            $this->writePayment($order, $paymentMethod);

            $this->recordHistory($order, null, $order->status, 'Order placed.');

            // The basket has become an order; leaving it filled would let the
            // customer accidentally buy the same thing twice.
            $cart->items()->delete();

            return $order->fresh(['items', 'taxes', 'addresses', 'payments']);
        });

        // Outside the transaction on purpose: an email must never go out for
        // an order that then rolls back, and a dead mail host must never undo
        // a sale that has already succeeded.
        $this->notifier->orderPlaced($order);

        return $order;
    }

    /**
     * Confirm payment: convert the reservation into a real stock decrement and
     * move the order into the fulfilment pipeline.
     */
    public function markPaid(Order $order, ?Payment $payment = null, ?User $actor = null): Order
    {
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $order;
        }

        // A payment can still land on a cancelled order: the customer had a
        // stale checkout tab open when the reservation sweeper released it.
        // Committing stock here would decrement inventory that was already
        // returned, so this refuses and the caller deals with the money —
        // StripeWebhookController flags it for a refund rather than swallowing
        // the event.
        if ($order->fulfillment_status === Order::FULFILLMENT_CANCELLED) {
            throw new RuntimeException(
                "Order {$order->order_number} was cancelled and cannot be marked paid."
            );
        }

        $order = DB::transaction(function () use ($order, $payment, $actor) {
            $this->commitStock($order);

            $payment ??= $order->payments()->latest('id')->first();
            $payment?->update([
                'status' => Payment::STATUS_SUCCEEDED,
                'paid_at' => now(),
            ]);

            $from = $order->status;

            $order->fill([
                'payment_status' => Order::PAYMENT_PAID,
                'fulfillment_status' => Order::FULFILLMENT_PROCESSING,
                'paid_at' => now(),
            ]);
            $order->status = $this->deriveStatus($order);
            $order->save();

            $this->recordHistory($order, $from, $order->status, 'Payment received.', $actor);

            return $order;
        });

        $this->notifier->paymentReceived($order);

        return $order;
    }

    /** Cancel an order, returning whatever stock it was holding (§7). */
    public function cancel(Order $order, ?string $reason = null, ?User $actor = null): Order
    {
        if (! $order->isCancellable()) {
            throw new RuntimeException('A shipped or completed order cannot be cancelled.');
        }

        $wasUnpaid = $order->payment_status !== Order::PAYMENT_PAID;

        $order = DB::transaction(function () use ($order, $reason, $actor) {
            $order->payment_status === Order::PAYMENT_PAID
                ? $this->restock($order, InventoryMovement::REASON_CANCELLATION)
                : $this->releaseReservation($order);

            $from = $order->status;

            $order->fill([
                'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
                'cancelled_at' => now(),
            ]);
            $order->status = $this->deriveStatus($order);
            $order->save();

            $this->recordHistory($order, $from, $order->status, $reason ?: 'Order cancelled.', $actor);

            return $order;
        });

        // After the commit, and never inside it: this is a network call, and a
        // provider timing out must not roll back a cancellation that has
        // already returned the stock. The stranded-intent risk it leaves is
        // covered on the other side — markPaid refuses a cancelled order.
        if ($wasUnpaid) {
            $this->payments->abandon($order->load('payments'));
        }

        return $order;
    }

    /**
     * Refund a paid order (§9).
     *
     * Two modes, and the difference matters:
     *
     *   $throughGateway = true  — actually send the money back through the
     *     provider, then record what it confirmed. The provider's own refund id
     *     becomes the ledger reference, so the two sides reconcile.
     *
     *   $throughGateway = false — record money already returned by other means:
     *     an e-Transfer sent by hand, or a refund issued from the Stripe
     *     dashboard and arriving here through the webhook. Moves no money.
     *
     * ORDER OF OPERATIONS. Validation first, then the provider call, then the
     * ledger — and the provider call sits OUTSIDE the transaction. Holding a
     * database transaction open across a network round trip locks the order row
     * for as long as the provider takes to answer, and a refund that succeeds
     * at the provider while the transaction rolls back is money gone with no
     * record of it. Better to write the ledger a moment late than to lose it.
     *
     * A partial refund leaves the order payable-complete but marks it
     * partially_refunded; refunding the full total moves it to Refunded.
     */
    public function refund(
        Order $order,
        int $amountCents,
        ?string $reason = null,
        bool $restock = true,
        ?string $providerReference = null,
        ?User $actor = null,
        bool $throughGateway = false,
    ): Refund {
        if ($order->payment_status !== Order::PAYMENT_PAID
            && $order->payment_status !== Order::PAYMENT_PARTIALLY_REFUNDED) {
            throw new RuntimeException('Only a paid order can be refunded.');
        }

        if ($amountCents <= 0) {
            throw new RuntimeException('A refund must be for more than zero.');
        }

        $remaining = $order->grand_total_cents - $order->totalRefundedCents();

        if ($amountCents > $remaining) {
            throw new RuntimeException(
                'That is more than the '.number_format($remaining / 100, 2).' still outstanding on this order.'
            );
        }

        $payment = $this->refundablePayment($order);

        if ($throughGateway) {
            // Throws on refusal, which aborts before anything is written — a
            // declined refund must not leave a ledger row claiming it happened.
            $providerReference = $this->payments
                ->refund($payment, $amountCents, $reason)
                ->reference;
        }

        return DB::transaction(function () use ($order, $payment, $amountCents, $reason, $restock, $providerReference, $actor, $throughGateway) {
            $refund = $order->refunds()->create([
                'payment_id' => $payment->id,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'provider_reference' => $providerReference,
                'restock' => $restock,
                'created_by' => $actor?->id,
            ]);

            if ($restock) {
                $this->restock($order, InventoryMovement::REASON_REFUND_RESTOCK);
            }

            // Queries the relation fresh, so the row just written is included.
            $isFull = $order->totalRefundedCents() >= $order->grand_total_cents;

            $from = $order->status;

            $order->payment_status = $isFull
                ? Order::PAYMENT_REFUNDED
                : Order::PAYMENT_PARTIALLY_REFUNDED;

            if ($isFull) {
                $payment->update(['status' => Payment::STATUS_REFUNDED]);
            }

            $order->status = $this->deriveStatus($order);
            $order->save();

            $this->recordHistory(
                $order,
                $from,
                $order->status,
                // "sent" and "recorded" are not the same claim, and the audit
                // trail is the wrong place to blur them.
                sprintf(
                    '%s refund of $%s %s.%s',
                    $isFull ? 'Full' : 'Partial',
                    number_format($amountCents / 100, 2),
                    $throughGateway ? 'sent via '.$payment->provider : 'recorded',
                    $reason ? " {$reason}" : '',
                ),
                $actor,
            );

            return $refund;
        });
    }

    /**
     * Move fulfilment forward. Illegal transitions are rejected rather than
     * silently accepted — a cancelled order cannot become shipped.
     */
    public function transitionFulfillment(
        Order $order,
        string $to,
        ?string $note = null,
        ?User $actor = null,
    ): Order {
        $allowed = self::FULFILLMENT_TRANSITIONS[$order->fulfillment_status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Cannot move fulfilment from {$order->fulfillment_status} to {$to}."
            );
        }

        $from = $order->status;

        $order->fulfillment_status = $to;

        if ($to === Order::FULFILLMENT_SHIPPED) {
            $order->shipped_at = now();
        }

        if ($to === Order::FULFILLMENT_COMPLETED) {
            $order->completed_at = now();
        }

        $order->status = $this->deriveStatus($order);
        $order->save();

        $this->recordHistory($order, $from, $order->status, $note, $actor);

        // Both mean the same thing to the customer: what they bought is now
        // available to them.
        if (in_array($to, [Order::FULFILLMENT_SHIPPED, Order::FULFILLMENT_READY_FOR_PICKUP], true)) {
            $this->notifier->orderShipped($order);
        }

        return $order;
    }

    /** @var array<string, array<int, string>> */
    public const FULFILLMENT_TRANSITIONS = [
        Order::FULFILLMENT_UNFULFILLED => [Order::FULFILLMENT_PROCESSING, Order::FULFILLMENT_CANCELLED],
        Order::FULFILLMENT_PROCESSING => [
            Order::FULFILLMENT_READY_FOR_PICKUP,
            Order::FULFILLMENT_SHIPPED,
            Order::FULFILLMENT_CANCELLED,
        ],
        Order::FULFILLMENT_READY_FOR_PICKUP => [Order::FULFILLMENT_COMPLETED, Order::FULFILLMENT_CANCELLED],
        Order::FULFILLMENT_SHIPPED => [Order::FULFILLMENT_COMPLETED],
        Order::FULFILLMENT_COMPLETED => [],
        Order::FULFILLMENT_CANCELLED => [],
    ];

    /**
     * Rule 3. Payment facts outrank fulfilment facts, because "Refunded" is what
     * a customer needs to see even on an order that also shipped.
     */
    public function deriveStatus(Order $order): string
    {
        if ($order->payment_status === Order::PAYMENT_REFUNDED) {
            return Order::STATUS_REFUNDED;
        }

        if ($order->fulfillment_status === Order::FULFILLMENT_CANCELLED) {
            return Order::STATUS_CANCELLED;
        }

        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return Order::STATUS_PENDING_PAYMENT;
        }

        return match ($order->fulfillment_status) {
            Order::FULFILLMENT_READY_FOR_PICKUP => Order::STATUS_READY_FOR_PICKUP,
            Order::FULFILLMENT_SHIPPED => Order::STATUS_SHIPPED,
            Order::FULFILLMENT_COMPLETED => Order::STATUS_COMPLETED,
            Order::FULFILLMENT_PROCESSING => Order::STATUS_PROCESSING,
            default => Order::STATUS_PAID,
        };
    }

    // ---------------------------------------------------------------- internals

    /**
     * The payment a refund should be taken against: the one that actually
     * succeeded, falling back to the most recent row so an order settled by
     * hand is still refundable.
     */
    protected function refundablePayment(Order $order): Payment
    {
        return $order->payments()
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->latest('id')
            ->first()
            ?? $order->payments()->latest('id')->firstOrFail();
    }

    protected function resolveShippingOption(
        array $input,
        bool $isPickup,
        PriceQuote $quote,
        Destination $destination,
    ): ShippingOption {
        if ($isPickup) {
            return $this->shipping->pickupOption();
        }

        $parcel = Parcel::fromQuotedLines($quote->lines, $quote->subtotalCents);
        $code = $input['shipping_option'] ?? null;

        $option = $code
            ? $this->shipping->findOption($code, $destination, $parcel, $quote->retailSubtotalCents)
            : null;

        if (! $option) {
            throw new RuntimeException('That shipping option is no longer available.');
        }

        return $option;
    }

    /** Rule 2: lock every variant row before checking availability. */
    protected function reserveStock(PriceQuote $quote): void
    {
        foreach ($quote->lines as $line) {
            $variant = ProductVariant::whereKey($line->variant->id)->lockForUpdate()->first();

            if (! $variant || $variant->availableStock() < $line->qty) {
                throw new RuntimeException(
                    "{$line->variant->sku} does not have {$line->qty} available."
                );
            }

            $variant->increment('reserved_qty', $line->qty);
        }
    }

    /**
     * Payment confirmed: the held units leave the shelf for real. The ledger row
     * is what makes a wrong stock count explainable later.
     */
    protected function commitStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();

            if (! $variant) {
                continue;
            }

            $variant->decrement('stock_qty', $item->qty);
            $variant->decrement('reserved_qty', min($item->qty, $variant->reserved_qty));

            $this->writeMovement($order, $variant->fresh(), -$item->qty, InventoryMovement::REASON_PURCHASE);
        }
    }

    /** Unpaid order cancelled: nothing ever left the shelf, so only the hold goes. */
    protected function releaseReservation(Order $order): void
    {
        foreach ($order->items as $item) {
            $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();

            $variant?->decrement('reserved_qty', min($item->qty, $variant->reserved_qty));
        }
    }

    protected function restock(Order $order, string $reason): void
    {
        foreach ($order->items as $item) {
            $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();

            if (! $variant) {
                continue;
            }

            $variant->increment('stock_qty', $item->qty);

            $this->writeMovement($order, $variant->fresh(), $item->qty, $reason);
        }
    }

    protected function writeMovement(Order $order, ProductVariant $variant, int $delta, string $reason): void
    {
        InventoryMovement::create([
            'product_variant_id' => $variant->id,
            'delta' => $delta,
            'balance_after' => $variant->stock_qty,
            'reason' => $reason,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    protected function writeItems(Order $order, PriceQuote $quote): void
    {
        foreach ($quote->lines as $line) {
            $variant = $line->variant;

            $order->items()->create([
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_sku' => $variant->sku,
                'color_name' => $variant->color?->name,
                'size_name' => $variant->size?->name,
                'secondary_size_name' => $variant->secondarySize?->name,
                'qty' => $line->qty,
                'unit_retail_cents' => $line->unitRetailCents,
                'unit_price_cents' => $line->unitPriceCents,
                'line_discount_cents' => $line->lineDiscountCents(),
                'line_total_cents' => $line->lineTotalCents(),
                'pricing_tier_name' => $quote->tier?->name,
            ]);
        }
    }

    protected function writeTaxes(Order $order, TaxQuote $taxQuote): void
    {
        foreach ($taxQuote->lines as $line) {
            $order->taxes()->create([
                'tax_type' => $line->taxType,
                'province' => $line->province,
                'rate_bps' => $line->rateBps,
                'taxable_base_cents' => $line->taxableBaseCents,
                'amount_cents' => $line->amountCents,
            ]);
        }
    }

    protected function writeAddress(Order $order, string $type, array $data): void
    {
        if ($data === []) {
            return;
        }

        $order->addresses()->create([
            'type' => $type,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'company' => $data['company'] ?? null,
            'line1' => $data['line1'] ?? null,
            'line2' => $data['line2'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => isset($data['province']) ? strtoupper($data['province']) : null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => strtoupper($data['country'] ?? 'CA'),
            'phone' => $data['phone'] ?? null,
        ]);
    }

    /**
     * Every order gets a pending payment row, whatever the method.
     *
     * For a card that row is what StripeGateway attaches its intent to, and
     * what the webhook finds again when the money lands. For e-Transfer it is
     * simply the thing an administrator marks paid. Both sit in Pending Payment
     * until something confirms them — the difference is only who confirms.
     */
    protected function writePayment(Order $order, string $method): void
    {
        $order->payments()->create([
            'provider' => $method,
            'method' => $method,
            'status' => Payment::STATUS_PENDING,
            'amount_cents' => $order->grand_total_cents,
            'currency' => $order->currency ?: 'CAD',
        ]);
    }

    protected function recordHistory(
        Order $order,
        ?string $from,
        string $to,
        ?string $note = null,
        ?User $actor = null,
    ): void {
        $order->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->id,
            'note' => $note,
            'notified_customer' => false,
        ]);
    }

    /** BSD-10001. Derived from the id, so it is unique without a counter table. */
    protected function orderNumberFor(Order $order): string
    {
        $prefix = $this->settings->string('orders.number_prefix', 'BSD-');
        $start = $this->settings->int('orders.number_start', 10000);

        return $prefix.($start + $order->id);
    }
}
