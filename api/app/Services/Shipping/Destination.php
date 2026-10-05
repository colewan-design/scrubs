<?php

namespace App\Services\Shipping;

/**
 * Where a parcel is going. Province is what every rate decision turns on.
 *
 * The street and the town ride along for the carrier's benefit: table rates
 * never look at them, but a live quote is for one actual address.
 */
final class Destination
{
    public function __construct(
        public readonly ?string $province,
        public readonly ?string $postalCode = null,
        public readonly string $country = 'CA',
        public readonly ?string $line1 = null,
        public readonly ?string $line2 = null,
        public readonly ?string $city = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            province: isset($data['province']) ? strtoupper((string) $data['province']) : null,
            postalCode: $data['postal_code'] ?? null,
            country: strtoupper((string) ($data['country'] ?? 'CA')),
            line1: self::text($data['line1'] ?? null),
            line2: self::text($data['line2'] ?? null),
            city: self::text($data['city'] ?? null),
        );
    }

    public function isKnown(): bool
    {
        return $this->province !== null && $this->province !== '';
    }

    /**
     * "L2H 2W9", or null until all six characters are there.
     *
     * Checkout re-quotes as the postal code is typed, and half of one is not
     * something a carrier can rate.
     */
    public function canadianPostalCode(): ?string
    {
        $code = strtoupper((string) preg_replace('/\s+/', '', (string) $this->postalCode));

        if ($this->country !== 'CA' || ! preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $code)) {
            return null;
        }

        return substr($code, 0, 3).' '.substr($code, 3);
    }

    /** Somewhere a carrier could actually deliver to, not just a region to price. */
    public function isStreetAddress(): bool
    {
        return $this->isKnown()
            && $this->line1 !== null
            && $this->city !== null
            && $this->canadianPostalCode() !== null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
