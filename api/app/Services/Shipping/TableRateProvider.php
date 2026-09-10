<?php

namespace App\Services\Shipping;

use App\Models\ShippingZone;

/**
 * Admin-configured zone/rate matrix (§5).
 *
 * Built first and kept permanently as the fallback, so checkout works whether
 * or not Stallion credentials ever arrive (Risk R3). Zones are ordered by
 * position and the FIRST zone covering the destination wins, which lets a
 * specific zone sit above a catch-all without priority logic anywhere else.
 */
class TableRateProvider implements ShippingProvider
{
    public function code(): string
    {
        return 'table_rate';
    }

    /** @return array<int, ShippingOption> */
    public function quote(Destination $destination, Parcel $parcel): array
    {
        if (! $destination->isKnown()) {
            return [];
        }

        $zone = ShippingZone::query()
            ->active()
            ->with(['rates' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('position')
            ->get()
            ->first(fn (ShippingZone $z) => $z->covers($destination->province));

        if (! $zone) {
            return [];
        }

        return $zone->rates
            ->filter(fn ($rate) => $rate->matches($parcel->weightGrams, $parcel->subtotalCents))
            ->map(fn ($rate) => new ShippingOption(
                code: 'table:'.$rate->id,
                name: $rate->name,
                costCents: $rate->costCents(),
                carrier: null,
                service: $rate->name,
                deliveryDaysMin: $rate->delivery_days_min,
                deliveryDaysMax: $rate->delivery_days_max,
                provider: $this->code(),
            ))
            ->values()
            ->all();
    }
}
