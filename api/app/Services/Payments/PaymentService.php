<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

/**
 * Which ways of paying exist right now, and how to drive them (§4).
 *
 * Two kinds of method live side by side and must not be confused:
 *
 *   GATEWAY methods move money through a provider and have a PaymentGateway.
 *   OFFLINE methods (e-Transfer, a payment an administrator records by hand)
 *   move money somewhere this system cannot see. They have no gateway, they
 *   never fail at checkout, and their orders sit in Pending Payment until a
 *   human settles them.
 *
 * Availability is deliberately the AND of two things — credentials present in
 * the environment, and the switch turned on in admin. Credentials alone must
 * not start charging cards the moment a key is pasted into `.env`, and the
 * switch alone must not offer a card form with nothing behind it.
 */
class PaymentService
{
    /** @var array<string, PaymentGateway> */
    protected array $gateways;

    public function __construct(
        protected Settings $settings,
        StripeGateway $stripe,
    ) {
        $this->gateways = [$stripe->code() => $stripe];
    }

    public function gateway(string $code): ?PaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }

    /** True for methods that move money through a provider. */
    public function isGateway(string $code): bool
    {
        return isset($this->gateways[$code]);
    }

    public function cardEnabled(): bool
    {
        return $this->settings->bool('payments.card_enabled')
            && $this->gateways[Payment::PROVIDER_STRIPE]->configured();
    }

    public function etransferEnabled(): bool
    {
        return $this->settings->bool('orders.etransfer_enabled');
    }

    /**
     * The methods checkout may offer, in the order they should be shown.
     *
     * Never empty: with nothing configured this still returns the offline
     * fallback, so the store can take an order and settle it by hand rather
     * than showing a customer a checkout with no way to finish.
     */
    public function availableMethods(): array
    {
        $methods = [];

        if ($this->cardEnabled()) {
            $stripe = $this->gateways[Payment::PROVIDER_STRIPE];

            $methods[] = [
                'code' => Payment::PROVIDER_STRIPE,
                'kind' => 'gateway',
                'label' => 'Credit or debit card',
                'description' => 'Visa, Mastercard, Amex — plus Apple Pay and Google Pay where supported.',
                // The card form has to mount before any payment exists, so the
                // publishable key travels with the quote rather than with the
                // session. Publishable is the whole point of it: it identifies
                // the account to Stripe.js and authorises nothing.
                'public_key' => $stripe instanceof StripeGateway ? $stripe->publishableKey() : null,
                'test_mode' => $stripe instanceof StripeGateway && $stripe->testMode(),
            ];
        }

        if ($this->etransferEnabled()) {
            $methods[] = [
                'code' => Payment::PROVIDER_ETRANSFER,
                'kind' => 'offline',
                'label' => 'Interac e-Transfer',
                'description' => 'We email instructions; your order is confirmed once payment arrives.',
                'test_mode' => false,
            ];
        }

        if ($methods === []) {
            $methods[] = [
                'code' => Payment::PROVIDER_MANUAL,
                'kind' => 'offline',
                'label' => 'Payment on confirmation',
                'description' => 'We contact you with payment instructions after the order is placed.',
                'test_mode' => false,
            ];
        }

        return $methods;
    }

    /** @return list<string> */
    public function availableCodes(): array
    {
        return array_column($this->availableMethods(), 'code');
    }

    /** Cards first when they are on, because that is what most customers want. */
    public function defaultMethod(): string
    {
        return $this->availableCodes()[0];
    }

    /**
     * Coerce whatever the client asked for into something actually offered.
     *
     * A client that posts `stripe` while cards are switched off must not get a
     * card order nobody can settle; it gets the default and the checkout
     * response tells it what it actually got.
     */
    public function resolveMethod(?string $requested): string
    {
        return in_array($requested, $this->availableCodes(), true)
            ? $requested
            : $this->defaultMethod();
    }

    /**
     * Open the provider-side payment for an order, if it needs one.
     *
     * Returns null for offline methods — there is nothing for the browser to
     * do, and checkout renders instructions instead of a card form.
     */
    public function prepare(Order $order): ?PaymentSession
    {
        $payment = $order->payments()->latest('id')->first();

        if (! $payment) {
            return null;
        }

        $gateway = $this->gateway($payment->provider);

        return $gateway?->prepare($order, $payment);
    }

    /**
     * Release an unpaid gateway payment provider-side. Best-effort by contract:
     * the caller is cancelling the order either way.
     */
    public function abandon(Order $order): void
    {
        foreach ($order->payments as $payment) {
            if ($payment->status !== Payment::STATUS_PENDING) {
                continue;
            }

            try {
                $this->gateway($payment->provider)?->abandon($payment);
            } catch (\Throwable $e) {
                Log::warning('Could not abandon payment.', [
                    'payment_id' => $payment->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send money back through whichever provider took it.
     *
     * Throws for an offline payment: there is no API behind an e-Transfer, and
     * silently recording a refund nobody sent would be the worst of the
     * available outcomes.
     */
    public function refund(Payment $payment, int $amountCents, ?string $reason = null): GatewayRefund
    {
        $gateway = $this->gateway($payment->provider);

        if (! $gateway) {
            throw new PaymentException(
                "Payments taken by {$payment->provider} have to be refunded by hand, then recorded here."
            );
        }

        $refund = $gateway->refund($payment, $amountCents, $reason);

        if (! $refund->succeeded()) {
            throw new PaymentException("The provider reported the refund as {$refund->status}.");
        }

        return $refund;
    }
}
