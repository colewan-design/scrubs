<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;

/**
 * A payment provider that moves money (§4).
 *
 * The interface exists because §4 asks for PayPal alongside cards, and a
 * Canadian Stripe account may not be able to offer PayPal through Stripe — see
 * the note in StripeGateway. Adding a second provider must be a new class here,
 * never a second branch through OrderService.
 *
 * Offline methods — e-Transfer, "record a manual payment" — deliberately do NOT
 * implement this. They move no money and have nothing to call.
 */
interface PaymentGateway
{
    /** Matches `payments.provider` on the stored row. */
    public function code(): string;

    /** Whether credentials exist. An unconfigured gateway is never offered. */
    public function configured(): bool;

    /**
     * Open (or re-open) a payment for an order, returning what the browser
     * needs to complete it.
     *
     * Must be idempotent per payment: a customer who reloads checkout has to
     * land back on the same provider-side object, not a second one that leaves
     * a duplicate authorisation on their card.
     */
    public function prepare(Order $order, Payment $payment): PaymentSession;

    /** Send money back. Called only after the ledger has agreed the amount. */
    public function refund(Payment $payment, int $amountCents, ?string $reason = null): GatewayRefund;

    /**
     * Abandon an unpaid payment provider-side, so a customer who wanders back
     * to a stale tab cannot complete a charge for an order we have released the
     * stock for. Failure here is logged, never thrown: the order is going away
     * either way.
     */
    public function abandon(Payment $payment): void;
}
