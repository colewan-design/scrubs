<?php

namespace App\Services\Shipping;

/**
 * The seam between the table-rate fallback and live carrier rating (§5).
 *
 * TableRateProvider is built first and kept permanently: if a live rate call
 * fails or times out at checkout, the sale must still complete. StallionProvider
 * implements the same interface once credentials arrive (Risk R3).
 */
interface ShippingProvider
{
    public function code(): string;

    /** @return array<int, ShippingOption> Empty means "cannot rate this" — the caller falls back. */
    public function quote(Destination $destination, Parcel $parcel): array;
}
