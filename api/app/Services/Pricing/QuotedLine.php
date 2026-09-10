<?php

namespace App\Services\Pricing;

use App\Models\ProductVariant;

/**
 * One priced cart line. `unitPriceCents` is what will actually be charged;
 * `unitRetailCents` is what it would have cost without any wholesale tier.
 */
final class QuotedLine
{
    public function __construct(
        public readonly ProductVariant $variant,
        public readonly int $qty,
        public readonly int $unitRetailCents,
        public readonly int $unitPriceCents,
        public readonly string $priceSource,
    ) {}

    public function lineRetailCents(): int
    {
        return $this->unitRetailCents * $this->qty;
    }

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents * $this->qty;
    }

    public function lineDiscountCents(): int
    {
        return $this->lineRetailCents() - $this->lineTotalCents();
    }

    public function toArray(): array
    {
        return [
            'product_variant_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'qty' => $this->qty,
            'unit_retail_cents' => $this->unitRetailCents,
            'unit_price_cents' => $this->unitPriceCents,
            'line_retail_cents' => $this->lineRetailCents(),
            'line_total_cents' => $this->lineTotalCents(),
            'line_discount_cents' => $this->lineDiscountCents(),
            'price_source' => $this->priceSource,
        ];
    }
}
