<?php

namespace App\Services\Tax;

/** The itemised tax on one order, plus the total that lands on the invoice. */
final class TaxQuote
{
    /** @param  array<int, TaxLine>  $lines */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $province,
    ) {}

    public static function none(?string $province = null): self
    {
        return new self([], $province);
    }

    public function totalCents(): int
    {
        return array_sum(array_map(fn (TaxLine $l) => $l->amountCents, $this->lines));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function toArray(): array
    {
        return [
            'province' => $this->province,
            'lines' => array_map(fn (TaxLine $l) => $l->toArray(), $this->lines),
            'total_cents' => $this->totalCents(),
        ];
    }
}
