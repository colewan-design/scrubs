<?php

namespace App\Services\Payments;

/**
 * What the browser needs in order to collect a payment, and nothing more.
 *
 * `clientSecret` is scoped by the provider to exactly one payment of exactly
 * one amount — it cannot be replayed to charge something else — and `publicKey`
 * is publishable by definition. Neither is a secret in the sense the API's own
 * keys are, which is why this object is safe to serialise straight to a
 * storefront that any visitor can load.
 */
final class PaymentSession
{
    public function __construct(
        public readonly string $provider,
        /** The provider-side object this payment belongs to — Stripe's `pi_…`. */
        public readonly string $reference,
        public readonly string $clientSecret,
        public readonly string $publicKey,
        /** Test-mode keys, so the storefront can say so out loud. */
        public readonly bool $testMode = false,
    ) {}

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'reference' => $this->reference,
            'client_secret' => $this->clientSecret,
            'public_key' => $this->publicKey,
            'test_mode' => $this->testMode,
        ];
    }
}
