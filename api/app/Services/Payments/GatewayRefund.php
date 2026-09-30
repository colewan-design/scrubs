<?php

namespace App\Services\Payments;

/** Money the provider confirms it has sent back. */
final class GatewayRefund
{
    public function __construct(
        /** The provider's own refund id — Stripe's `re_…`. Reconciles the ledger. */
        public readonly string $reference,
        public readonly int $amountCents,
        public readonly string $status,
    ) {}

    /**
     * Stripe returns `pending` for refunds to some card networks and to
     * anything settled asynchronously. Pending is still a commitment to pay, so
     * it counts as success — only an outright failure is not.
     */
    public function succeeded(): bool
    {
        return in_array($this->status, ['succeeded', 'pending'], true);
    }
}
