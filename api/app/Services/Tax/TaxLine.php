<?php

namespace App\Services\Tax;

/** One applied tax, itemised the way §6 requires it to print: "HST 13% — $8.45". */
final class TaxLine
{
    public function __construct(
        public readonly string $taxType,
        public readonly string $province,
        public readonly int $rateBps,
        public readonly int $taxableBaseCents,
        public readonly int $amountCents,
    ) {}

    /** Trailing zeros trimmed, so 1300 bps reads "13%" and 998 reads "9.98%". */
    public function label(): string
    {
        $percent = rtrim(rtrim(number_format($this->rateBps / 100, 2, '.', ''), '0'), '.');

        return "{$this->taxType} {$percent}%";
    }

    public function toArray(): array
    {
        return [
            'tax_type' => $this->taxType,
            'province' => $this->province,
            'rate_bps' => $this->rateBps,
            'label' => $this->label(),
            'taxable_base_cents' => $this->taxableBaseCents,
            'amount_cents' => $this->amountCents,
        ];
    }
}
