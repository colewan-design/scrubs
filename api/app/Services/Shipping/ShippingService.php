<?php

namespace App\Services\Shipping;

use App\Support\Settings;
use Throwable;

/**
 * Decides what a customer is offered and charged for delivery (§5).
 *
 * TWO RULES THIS CLASS MUST NEVER BREAK
 *
 *  1. Free shipping comes from the same setting the storefront advertises.
 *     The cart already tells a shopper how far they are from free shipping
 *     using `shipping.free_threshold_cents`; if checkout priced it any other
 *     way, the site would promise one thing and charge another. Qualification
 *     is tested against the PRE-DISCOUNT retail subtotal for the same reason
 *     PricingService does it — a discount must never revoke the threshold that
 *     produced it.
 *
 *  2. A live rating failure must never block a sale. Anything thrown by a
 *     live provider falls through to table rates rather than surfacing.
 *
 * Local pickup bypasses rating entirely: it is a fulfilment type, not a rate.
 */
class ShippingService
{
    public const PICKUP_CODE = 'pickup';

    public function __construct(
        protected Settings $settings,
        protected TableRateProvider $tableRates,
    ) {}

    /**
     * @param  int  $retailSubtotalCents  Pre-discount, matching PricingService.
     * @return array<int, ShippingOption>
     */
    public function options(Destination $destination, Parcel $parcel, int $retailSubtotalCents): array
    {
        $options = $this->rateOptions($destination, $parcel);
        $options = $this->applyFreeThreshold($options, $retailSubtotalCents);

        if ($this->settings->bool('pickup.enabled')) {
            // Pickup is appended last: it is a genuine choice, but delivery is
            // what most shoppers want and should lead.
            $options[] = $this->pickupOption();
        }

        return $options;
    }

    /** Looks up one previously quoted option by code, so order creation re-prices rather than trusts. */
    public function findOption(
        string $code,
        Destination $destination,
        Parcel $parcel,
        int $retailSubtotalCents,
    ): ?ShippingOption {
        foreach ($this->options($destination, $parcel, $retailSubtotalCents) as $option) {
            if ($option->code === $code) {
                return $option;
            }
        }

        return null;
    }

    public function pickupOption(): ShippingOption
    {
        return new ShippingOption(
            code: self::PICKUP_CODE,
            name: 'Local pickup',
            costCents: 0,
            provider: self::PICKUP_CODE,
        );
    }

    /** @return array<int, ShippingOption> */
    protected function rateOptions(Destination $destination, Parcel $parcel): array
    {
        $provider = $this->settings->string('shipping.provider', 'table_rate');

        if ($provider !== 'table_rate' && $provider !== '') {
            try {
                $live = $this->liveProvider($provider)?->quote($destination, $parcel) ?? [];

                if ($live !== []) {
                    return $live;
                }
            } catch (Throwable) {
                // Rule 2: a carrier outage is not the customer's problem.
            }
        }

        return $this->tableRates->quote($destination, $parcel);
    }

    /**
     * StallionProvider binds here once credentials arrive (§5, Risk R3). Until
     * then the setting can only ever resolve to table rates.
     */
    protected function liveProvider(string $code): ?ShippingProvider
    {
        $binding = "shipping.provider.{$code}";

        return app()->bound($binding) ? app($binding) : null;
    }

    /**
     * @param  array<int, ShippingOption>  $options
     * @return array<int, ShippingOption>
     */
    protected function applyFreeThreshold(array $options, int $retailSubtotalCents): array
    {
        $threshold = $this->settings->freeShippingThresholdCents();

        if ($threshold <= 0 || $retailSubtotalCents < $threshold || $options === []) {
            return $options;
        }

        // Only the cheapest option becomes free. An express upgrade is a service
        // the customer chose to pay for, and making it free at $600 would give
        // away the fastest service on every wholesale order.
        $cheapestIndex = 0;

        foreach ($options as $i => $option) {
            if ($option->costCents < $options[$cheapestIndex]->costCents) {
                $cheapestIndex = $i;
            }
        }

        $options[$cheapestIndex] = $options[$cheapestIndex]->withCost(0, freeThresholdApplied: true);

        return $options;
    }
}
