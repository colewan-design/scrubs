<?php

namespace App\Services\Shipping;

/** Where a parcel is going. Province is what every rate decision turns on. */
final class Destination
{
    public function __construct(
        public readonly ?string $province,
        public readonly ?string $postalCode = null,
        public readonly string $country = 'CA',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            province: isset($data['province']) ? strtoupper((string) $data['province']) : null,
            postalCode: $data['postal_code'] ?? null,
            country: strtoupper((string) ($data['country'] ?? 'CA')),
        );
    }

    public function isKnown(): bool
    {
        return $this->province !== null && $this->province !== '';
    }
}
