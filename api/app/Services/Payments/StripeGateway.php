<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe, via Payment Intents (§4).
 *
 * WHY PAYMENT INTENTS AND NOT HOSTED CHECKOUT
 *
 * Stripe Checkout would have been a day's work, but it redirects the customer
 * off-site at the exact moment the design brief cares about most, and §1 asks
 * for a premium on-site experience. Payment Intents keep the checkout page the
 * one that was designed, and the card fields themselves are Stripe-hosted
 * iframes — the card number never touches this server or even our own DOM, so
 * the PCI position stays SAQ-A exactly as §12 requires.
 *
 * WHAT THIS CLASS IS NOT ALLOWED TO DECIDE
 *
 * The amount. It reads `grand_total_cents` off an order that OrderService has
 * already written, and never recomputes anything. A gateway that can arrive at
 * its own total is a gateway that can disagree with the receipt.
 *
 * ABOUT PAYPAL (§4)
 *
 * The brief asks for PayPal. Stripe offers PayPal as a payment method only in
 * certain regions, and Canadian accounts have historically not been among them
 * — this must be checked against the live account before launch rather than
 * assumed. If it turns out unavailable, PayPal is a second PaymentGateway
 * implementation alongside this one, which is why that interface exists.
 * Cards, Apple Pay, Google Pay and Link all arrive here automatically through
 * `automatic_payment_methods`, with no code change per method.
 */
class StripeGateway implements PaymentGateway
{
    /** Intent states that can still be completed by the customer. */
    private const REUSABLE = [
        'requires_payment_method',
        'requires_confirmation',
        'requires_action',
        'processing',
    ];

    public function code(): string
    {
        return Payment::PROVIDER_STRIPE;
    }

    public function configured(): bool
    {
        return $this->secret() !== '' && $this->publicKey() !== '';
    }

    public function testMode(): bool
    {
        return str_starts_with($this->publicKey(), 'pk_test_');
    }

    /** Safe to hand to a browser — that is what "publishable" means. */
    public function publishableKey(): string
    {
        return $this->publicKey();
    }

    /** Whether inbound webhooks can be authenticated. Checkout works without it; settling does not. */
    public function webhookReady(): bool
    {
        return (string) config('services.stripe.webhook_secret') !== '';
    }

    public function prepare(Order $order, Payment $payment): PaymentSession
    {
        if (! $this->configured()) {
            throw PaymentException::notConfigured('Stripe');
        }

        $existing = $this->existingIntent($payment);

        // The customer has already paid and only the webhook is outstanding.
        // A session handed back here is a card form for a payment that cannot
        // be made twice — and a day later, once Stripe has forgotten the
        // idempotency key, createIntent() below would open a genuinely new
        // intent and a second charge. Neither: say what is true and let the
        // webhook finish the job.
        if ($existing?->status === 'succeeded') {
            throw new PaymentAlreadyReceived(
                'Your payment has already gone through and is being confirmed. '
                .'Please do not pay again — your receipt will arrive by email shortly.'
            );
        }

        // Reuse rather than recreate, so a reloaded checkout does not open a
        // second authorisation.
        $intent = $existing && in_array($existing->status, self::REUSABLE, true)
            ? $existing
            : $this->createIntent($order, $payment);

        // Belt and braces. An intent whose amount has drifted from the order it
        // belongs to would charge the wrong money, so refuse rather than guess.
        if ($intent->amount !== $order->grand_total_cents) {
            throw new PaymentException(
                'This order has changed since payment was started. Please refresh and try again.'
            );
        }

        $payment->forceFill([
            'provider_reference' => $intent->id,
            'method' => 'card',
        ])->save();

        return new PaymentSession(
            provider: $this->code(),
            reference: $intent->id,
            clientSecret: (string) $intent->client_secret,
            publicKey: $this->publicKey(),
            testMode: $this->testMode(),
        );
    }

    public function refund(Payment $payment, int $amountCents, ?string $reason = null): GatewayRefund
    {
        if (! $this->configured()) {
            throw PaymentException::notConfigured('Stripe');
        }

        if (! $payment->provider_reference) {
            throw new PaymentException('This payment has no Stripe reference to refund against.');
        }

        try {
            $refund = $this->withNoticesLogged(fn () => $this->client()->refunds->create([
                'payment_intent' => $payment->provider_reference,
                'amount' => $amountCents,
                // Stripe accepts only three canned reasons; the administrator's
                // own words go to metadata so they survive on the Stripe side
                // too, where the finance reconciliation actually happens.
                'reason' => 'requested_by_customer',
                // `order_id` does a second job: it is how refundsMadeElsewhere()
                // tells a refund issued here from one issued in the dashboard.
                'metadata' => array_filter([
                    'order_id' => (string) $payment->order_id,
                    'note' => $reason ? mb_substr($reason, 0, 490) : null,
                ]),
            ]));
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'The refund was refused by Stripe.');
        }

        return new GatewayRefund(
            reference: (string) $refund->id,
            amountCents: (int) $refund->amount,
            status: (string) $refund->status,
        );
    }

    public function abandon(Payment $payment): void
    {
        if (! $this->configured() || ! $payment->provider_reference) {
            return;
        }

        try {
            $intent = $this->withNoticesLogged(
                fn () => $this->client()->paymentIntents->retrieve($payment->provider_reference)
            );

            if (in_array($intent->status, self::REUSABLE, true) && $intent->status !== 'processing') {
                $this->withNoticesLogged(
                    fn () => $this->client()->paymentIntents->cancel($payment->provider_reference, [
                        'cancellation_reason' => 'abandoned',
                    ])
                );
            }
        } catch (ApiErrorException $e) {
            // The order is being released regardless. A stranded intent at
            // Stripe expires on its own and cannot be completed against an
            // order that no longer holds stock, because the webhook checks.
            Log::warning('Stripe: could not cancel abandoned intent.', [
                'payment_intent' => $payment->provider_reference,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refunds Stripe holds against this payment that were not started here —
     * in practice, ones issued from the Stripe dashboard.
     *
     * Asked for, because it is no longer told: `charge.refunded` has not
     * carried the charge's refunds since API version 2022-11-15, so the webhook
     * learns only that a refund happened somewhere. Refunds this application
     * issued are left out, by the `order_id` refund() stamps on each one. The
     * request that started those writes their ledger row itself, a moment
     * after Stripe answers — and a webhook arriving inside that moment must not
     * write a second.
     *
     * Throws when Stripe cannot be asked. "None" and "could not find out" are
     * different answers, and the caller has to know which one it got.
     *
     * @return list<GatewayRefund>
     */
    public function refundsMadeElsewhere(Payment $payment): array
    {
        if (! $this->configured() || ! $payment->provider_reference) {
            return [];
        }

        try {
            $refunds = $this->withNoticesLogged(fn () => $this->client()->refunds->all([
                'payment_intent' => $payment->provider_reference,
                'limit' => 100,
            ]));
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'Stripe could not be asked about the refunds on this payment.');
        }

        $elsewhere = [];

        foreach ($refunds->data as $refund) {
            if (($refund->metadata['order_id'] ?? null) !== null) {
                continue;
            }

            $elsewhere[] = new GatewayRefund(
                reference: (string) $refund->id,
                amountCents: (int) $refund->amount,
                status: (string) $refund->status,
            );
        }

        return $elsewhere;
    }

    /**
     * Brand and last four of the card a payment was made with — nothing more.
     *
     * Fetched, because the event that reports a payment names its charge by id
     * and no longer carries it. Best-effort by contract: null when the payment
     * was not made with a card, and null when Stripe cannot be asked, because a
     * missing "Visa •••• 4242" on the admin page must never stand between a
     * paid order and being marked paid.
     *
     * @return array{brand: string, last4: string}|null
     */
    public function cardDetails(string $intentId): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $intent = $this->withNoticesLogged(fn () => $this->client()->paymentIntents->retrieve(
                $intentId,
                ['expand' => ['latest_charge']],
            ));
        } catch (ApiErrorException $e) {
            Log::warning('Stripe: could not read card details.', [
                'payment_intent' => $intentId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $charge = $intent->latest_charge;
        $card = is_object($charge) ? ($charge->payment_method_details->card ?? null) : null;

        if (! $card || ! $card->last4) {
            return null;
        }

        return ['brand' => (string) $card->brand, 'last4' => (string) $card->last4];
    }

    /**
     * Authenticate an inbound webhook.
     *
     * Throws rather than returning null on a bad signature: a webhook that
     * cannot be verified is an anonymous POST claiming an order has been paid,
     * and the only safe response is to refuse it.
     */
    public function verifyWebhook(string $payload, ?string $signature): \Stripe\Event
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            throw new PaymentException('No Stripe webhook secret is configured.');
        }

        try {
            return Webhook::constructEvent($payload, (string) $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new PaymentException('Invalid webhook signature: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------------- internals

    /** The intent this payment already has at Stripe, in whatever state it is now. */
    protected function existingIntent(Payment $payment): ?PaymentIntent
    {
        if (! $payment->provider_reference) {
            return null;
        }

        try {
            return $this->withNoticesLogged(
                fn () => $this->client()->paymentIntents->retrieve($payment->provider_reference)
            );
        } catch (ApiErrorException) {
            return null;
        }
    }

    protected function createIntent(Order $order, Payment $payment): PaymentIntent
    {
        try {
            return $this->withNoticesLogged(fn () => $this->client()->paymentIntents->create([
                'amount' => $order->grand_total_cents,
                'currency' => strtolower($order->currency ?: 'cad'),
                // Every method enabled on the Stripe account is offered without
                // naming any of them here — Apple Pay and Google Pay appear by
                // themselves on devices that support them.
                'automatic_payment_methods' => ['enabled' => true],
                'receipt_email' => $order->email,
                'description' => 'BulkScrubs Direct '.$order->order_number,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'payment_id' => (string) $payment->id,
                ],
            ], [
                // Survives a retried request: the same payment row can only ever
                // produce the one intent.
                'idempotency_key' => 'bsd-payment-'.$payment->id,
            ]));
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'We could not start the payment. Please try again.');
        }
    }

    /**
     * Run one SDK call with Stripe's advisories logged instead of thrown.
     *
     * Stripe attaches a `Stripe-Notice` header to a response when it has
     * something to say to the integrator — "you are using an outdated API
     * version" being the one that prompted this. stripe-php raises that header
     * as an E_USER_WARNING, and Laravel turns every warning into an
     * ErrorException. By then the request has already succeeded: the intent
     * exists at Stripe, and the exception only stops this application finding
     * out. Checkout answered 500 to a customer whose order had been placed and
     * whose cart had been emptied, over a message written for a developer.
     *
     * So the advice goes to the log, where the developer it is addressed to
     * will see it, and the call carries on. Scoped to the one call rather than
     * installed once, so a warning from anywhere else in the request is still
     * the error it always was.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function withNoticesLogged(callable $call): mixed
    {
        set_error_handler(function (int $level, string $message): bool {
            Log::warning('Stripe notice.', ['message' => $message]);

            return true;
        }, E_USER_WARNING);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * No `stripe_version` here, deliberately.
     *
     * Left alone, the SDK speaks the API version it was generated against, so
     * the version moves only when composer.lock moves stripe-php — a reviewed
     * change — and the two cannot drift apart. It used to be pinned by hand to
     * 2024-06-20, which stayed where it was while the SDK moved on, until
     * Stripe declared it outdated and the notice that came back took checkout
     * down.
     */
    protected function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->secret()]);
    }

    protected function secret(): string
    {
        return (string) config('services.stripe.secret');
    }

    protected function publicKey(): string
    {
        return (string) config('services.stripe.key');
    }

    /**
     * Stripe's own message is shown for card errors — "Your card was declined"
     * is better than anything written here, and it is localised. Everything
     * else (network, auth, our own bad request) is logged in full and replaced,
     * because it names internals a customer must not see.
     */
    protected function translate(ApiErrorException $e, string $fallback): PaymentException
    {
        Log::error('Stripe API error.', [
            'type' => $e->getError()?->type,
            'code' => $e->getError()?->code,
            'message' => $e->getMessage(),
        ]);

        $isCardError = $e->getError()?->type === 'card_error';

        return new PaymentException(
            $isCardError ? (string) $e->getError()?->message : $fallback,
            previous: $e,
        );
    }
}
