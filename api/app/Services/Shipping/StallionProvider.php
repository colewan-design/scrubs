<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live rating through Stallion Express (§5, Risk R3).
 *
 * Written against Stallion's published rate endpoint but NOT yet verified
 * against the live API — that needs an account and a key, which are outstanding
 * client material. Two consequences worth being explicit about:
 *
 *  1. It stays unbound until `shipping.provider` is switched to `stallion` in
 *     Store settings, so nothing here can affect checkout today.
 *
 *  2. Field names in `parseRates()` are the ones to check first when the key
 *     arrives. The shape of the response is the part most likely to differ from
 *     what is assumed here.
 *
 * Every failure path returns an empty array rather than throwing. ShippingService
 * treats that as "cannot rate this" and falls back to table rates — a carrier
 * outage must never be able to stop a customer checking out.
 */
class StallionProvider implements ShippingProvider
{
    public function code(): string
    {
        return 'stallion';
    }

    public function quote(Destination $destination, Parcel $parcel): array
    {
        if (! $this->configured() || ! $destination->postalCode) {
            return [];
        }

        try {
            $response = Http::withToken(config('services.stallion.key'))
                ->acceptJson()
                // Short: this call sits in the checkout request, so a slow
                // carrier must degrade to table rates quickly rather than make
                // the customer wait.
                ->timeout(config('services.stallion.timeout', 6))
                ->post(rtrim(config('services.stallion.base_url'), '/').'/rates', [
                    'postal_code' => $destination->postalCode,
                    'province_code' => $destination->province,
                    'country_code' => $destination->country,
                    'weight' => $this->weightKg($parcel),
                    'weight_unit' => 'kg',
                    'value' => round($parcel->subtotalCents / 100, 2),
                    'currency' => 'CAD',
                    'package_type' => 'Parcel',
                ]);

            if (! $response->successful()) {
                Log::warning('Stallion rate request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $this->parseRates($response->json());
        } catch (Throwable $e) {
            Log::warning('Stallion rate request threw.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<mixed>|null  $payload
     * @return array<int, ShippingOption>
     */
    protected function parseRates(?array $payload): array
    {
        // Stallion has wrapped its collections in `data` historically; accept
        // either shape rather than depending on one.
        $rates = $payload['rates'] ?? $payload['data'] ?? $payload ?? [];

        if (! is_array($rates)) {
            return [];
        }

        $options = [];

        foreach ($rates as $rate) {
            if (! is_array($rate)) {
                continue;
            }

            $costCents = $this->toCents($rate['total'] ?? $rate['rate'] ?? $rate['price'] ?? null);

            if ($costCents === null) {
                continue;
            }

            $carrier = $rate['carrier'] ?? $rate['carrier_name'] ?? 'Stallion';
            $service = $rate['service'] ?? $rate['service_name'] ?? 'Standard';

            $options[] = new ShippingOption(
                code: 'stallion:'.str($carrier.'-'.$service)->slug(),
                name: trim($carrier.' '.$service),
                costCents: $costCents,
                carrier: $carrier,
                service: $service,
                deliveryDaysMin: isset($rate['delivery_days_min']) ? (int) $rate['delivery_days_min'] : null,
                deliveryDaysMax: isset($rate['delivery_days_max']) ? (int) $rate['delivery_days_max'] : null,
                provider: $this->code(),
            );
        }

        return $options;
    }

    /** Money crosses the wire as a decimal string; it becomes cents immediately. */
    protected function toCents(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * Carriers rate in kilograms. Items with no captured weight fall back to a
     * nominal figure so an incomplete catalogue cannot produce a zero-weight
     * parcel and, with it, an impossibly cheap rate.
     */
    protected function weightKg(Parcel $parcel): float
    {
        $grams = $parcel->weightGrams + ($parcel->unweighedItems * 300);

        return max(0.1, round($grams / 1000, 3));
    }

    protected function configured(): bool
    {
        return (bool) config('services.stallion.key')
            && (bool) config('services.stallion.base_url');
    }
}
