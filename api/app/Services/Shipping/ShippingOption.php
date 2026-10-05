<?php

namespace App\Services\Shipping;

/** One shipping choice offered at checkout. */
final class ShippingOption
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $costCents,
        public readonly ?string $carrier = null,
        public readonly ?string $service = null,
        public readonly ?int $deliveryDaysMin = null,
        public readonly ?int $deliveryDaysMax = null,
        public readonly string $provider = 'table_rate',
        /** Set when a free-shipping threshold overrode the table price. */
        public readonly bool $freeThresholdApplied = false,
        /**
         * The carrier's own name for the service ("canada_post.expedited"),
         * where there is one. Not shown to anybody: it is what buying the
         * label asks for, so it is kept with the order.
         */
        public readonly ?string $serviceCode = null,
    ) {}

    public function withCost(int $costCents, bool $freeThresholdApplied = false): self
    {
        return new self(
            $this->code, $this->name, $costCents, $this->carrier, $this->service,
            $this->deliveryDaysMin, $this->deliveryDaysMax, $this->provider, $freeThresholdApplied,
            $this->serviceCode,
        );
    }

    /** "3–7 business days", or null when the rate row does not say. */
    public function deliveryEstimate(): ?string
    {
        if ($this->deliveryDaysMin === null && $this->deliveryDaysMax === null) {
            return null;
        }

        $min = $this->deliveryDaysMin ?? $this->deliveryDaysMax;
        $max = $this->deliveryDaysMax ?? $this->deliveryDaysMin;

        if ($min === $max) {
            return $min === 1 ? '1 business day' : "{$min} business days";
        }

        return "{$min}–{$max} business days";
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'cost_cents' => $this->costCents,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'delivery_estimate' => $this->deliveryEstimate(),
            'provider' => $this->provider,
            'free_threshold_applied' => $this->freeThresholdApplied,
        ];
    }
}
