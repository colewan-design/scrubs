<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\GatewayRefund;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentSession;
use App\Services\Payments\StripeGateway;

/**
 * StripeGateway with the network removed and nothing else.
 *
 * Only the three methods that call Stripe are replaced. `configured()`,
 * `testMode()`, `webhookReady()` and — most importantly — `verifyWebhook()` are
 * inherited and run for real, so the signature check every webhook test depends
 * on is the actual production code path rather than a stub that always agrees.
 */
class FakeStripeGateway extends StripeGateway
{
    public array $refunded = [];

    public array $abandoned = [];

    public bool $failPrepare = false;

    public ?string $failRefundWith = null;

    public function prepare(Order $order, Payment $payment): PaymentSession
    {
        if ($this->failPrepare) {
            throw new PaymentException('We could not start the payment. Please try again.');
        }

        $reference = $payment->provider_reference ?: 'pi_test_'.$payment->id;

        $payment->forceFill([
            'provider_reference' => $reference,
            'method' => 'card',
        ])->save();

        return new PaymentSession(
            provider: 'stripe',
            reference: $reference,
            clientSecret: $reference.'_secret_test',
            publicKey: 'pk_test_fake',
            testMode: true,
        );
    }

    public function refund(Payment $payment, int $amountCents, ?string $reason = null): GatewayRefund
    {
        if ($this->failRefundWith) {
            throw new PaymentException($this->failRefundWith);
        }

        $this->refunded[] = ['payment' => $payment->id, 'amount' => $amountCents, 'reason' => $reason];

        return new GatewayRefund(
            reference: 're_test_'.count($this->refunded),
            amountCents: $amountCents,
            status: 'succeeded',
        );
    }

    public function abandon(Payment $payment): void
    {
        $this->abandoned[] = $payment->id;
    }
}
