<?php

namespace App\Services\Pricing;

use App\Models\ProductVariant;

/**
 * Input to PricingService — a variant and a quantity. Deliberately carries no
 * price: prices are resolved server-side at quote time, never passed in.
 */
final class CartLine
{
    public function __construct(
        public readonly ProductVariant $variant,
        public readonly int $qty,
    ) {}

    public static function make(ProductVariant $variant, int $qty): self
    {
        return new self($variant, max(0, $qty));
    }
}
