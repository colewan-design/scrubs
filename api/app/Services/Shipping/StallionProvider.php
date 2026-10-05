<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live rating through Stallion Express (§5, Risk R3).
 *
 * Stallion's v5 API (docs.stallion.ca). First run against the live service on
 * 2026-10-05, which is where two things its documentation calls optional
 * turned out to be required — a size for the parcel and a list of what is in
 * it — and where the ceiling on declared value was met. `shipment()` and
 * `usable()` are the two places to look first if a response ever disagrees
 * with what is assumed here.
 *
 * WHAT THE CUSTOMER IS OFFERED
 *
 * Stallion answers with every service on the lane — a dozen or more for a city
 * address. Checkout offers two of them:
 *
 *   Standard — the cheapest tracked service.
 *   Express  — the fastest, where anything is faster than Standard. Between
 *              services that are equally fast, the cheapest.
 *
 * Which carrier each turned out to be travels with the option and is kept on
 * the order, so whoever packs it knows which label to buy.
 *
 * The price is Stallion's pre-tax figure. Tax on shipping is TaxService's
 * decision, made per province like every other tax on the order; taking
 * Stallion's tax-inclusive total would charge it twice.
 *
 * Every failure path returns an empty array rather than throwing. ShippingService
 * treats that as "cannot rate this" and falls back to table rates — a carrier
 * outage must never be able to stop a customer checking out.
 */
class StallionProvider implements ShippingProvider
{
    public const STANDARD = 'stallion:standard';

    public const EXPRESS = 'stallion:express';

    /** Addresses one customer may have rated afresh within the window below. */
    public const LOOKUPS_PER_CUSTOMER = 30;

    public const LOOKUP_WINDOW_SECONDS = 600;

    public function code(): string
    {
        return 'stallion';
    }

    public function quote(Destination $destination, Parcel $parcel): array
    {
        // A carrier quotes a delivery, not a region. Until the address is
        // whole there is nothing to send, and table rates stand in.
        if (! $this->configured() || ! $destination->isStreetAddress()) {
            return [];
        }

        return $this->choose($this->rates($destination, $this->shipment($parcel)));
    }

    /**
     * Stallion's rates for this shipment to this postal code.
     *
     * Remembered for a while, for two reasons. Checkout asks again every time
     * the customer changes anything, and once more when the order is placed —
     * and the price charged then has to be the price shown a minute earlier,
     * not whatever a second round of carrier calls happens to return.
     *
     * Keyed on the postal code rather than the whole address: that is what a
     * carrier prices on, and it lets a corrected unit number reuse the answer.
     * Only a real answer is kept. A failure is asked again next time.
     *
     * @param  array<string, mixed>  $shipment
     * @return array<int, mixed>
     */
    protected function rates(Destination $destination, array $shipment): array
    {
        $key = 'stallion.rates.'.sha1(implode('|', [
            config('services.stallion.base_url'),
            $destination->country,
            $destination->province,
            $destination->canadianPostalCode(),
            json_encode($shipment),
        ]));

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if (! $this->mayAsk()) {
            return [];
        }

        $rates = $this->fetch($destination, $shipment);

        if ($rates !== []) {
            Cache::put($key, $rates, now()->addMinutes((int) config('services.stallion.cache_minutes', 30)));
        }

        return $rates;
    }

    /**
     * Whether this customer may send Stallion another address.
     *
     * Somebody checking out needs one answer, perhaps three if they correct
     * the postal code. A signed-in account looping over postal codes is using
     * this site to spend Stallion's rate limit, and once that is gone nobody
     * gets a live rate. Past the ceiling the answer is table rates — the same
     * thing that happens when Stallion is down, and never an error.
     */
    protected function mayAsk(): bool
    {
        $key = 'stallion.rates:'.(Auth::id() ?: request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::LOOKUPS_PER_CUSTOMER)) {
            return false;
        }

        RateLimiter::hit($key, self::LOOKUP_WINDOW_SECONDS);

        return true;
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @return array<int, mixed>
     */
    protected function fetch(Destination $destination, array $shipment): array
    {
        // This call sits in the checkout request, so it is bounded: a carrier
        // that will not answer must degrade to table rates rather than leave
        // the customer waiting. A complete answer takes Stallion three to
        // four seconds, which is why the ceiling is no lower than it is.
        $timeout = max(4, (int) config('services.stallion.timeout', 10));

        try {
            $response = Http::withToken(config('services.stallion.key'))
                ->acceptJson()
                // Stallion sits behind Cloudflare, which turns away clients it
                // cannot identify, and asks integrations to name themselves.
                ->withUserAgent('BulkScrubsDirect-Storefront/1.0')
                ->timeout($timeout)
                ->post(rtrim(config('services.stallion.base_url'), '/').'/rates', [
                    'to_address' => [
                        // Required by Stallion and irrelevant to a price. A
                        // quote saves nothing on their side, so the customer's
                        // name stays here until there is a label to put it on.
                        'name' => 'Customer',
                        'address1' => $destination->line1,
                        'address2' => $destination->line2,
                        'city' => $destination->city,
                        'province_code' => $destination->province,
                        'postal_code' => $destination->canadianPostalCode(),
                        'country_code' => $destination->country,
                    ],
                    ...$shipment,
                    // Stallion's own cut-off for slow carriers, set inside
                    // ours: it answers with whoever has replied, rather than
                    // us hanging up on all of them.
                    'timeout' => $timeout - 3,
                ]);

            if (! $response->successful()) {
                Log::warning('Stallion rate request failed.', [
                    'status' => $response->status(),
                    'error' => $response->json('error') ?? Str::limit($response->body(), 500),
                ]);

                return [];
            }

            $rates = $response->json('data');

            return is_array($rates) ? $rates : [];
        } catch (Throwable $e) {
            Log::warning('Stallion rate request threw.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, mixed>  $rates
     * @return array<int, ShippingOption>
     */
    protected function choose(array $rates): array
    {
        $usable = $this->usable($rates);

        if ($usable === []) {
            return [];
        }

        // Cheapest first; between two at the same price, the quicker. A
        // service with no estimate counts as slower than any that has one.
        usort($usable, fn (array $a, array $b) => [$a['cents'], $a['days'] ?? PHP_INT_MAX]
            <=> [$b['cents'], $b['days'] ?? PHP_INT_MAX]);

        $standard = $usable[0];

        $faster = array_values(array_filter(
            $usable,
            fn (array $rate) => $rate['days'] !== null && $rate['days'] < ($standard['days'] ?? PHP_INT_MAX),
        ));

        usort($faster, fn (array $a, array $b) => [$a['days'], $a['cents']] <=> [$b['days'], $b['cents']]);

        $options = [$this->option(self::STANDARD, 'Standard', $standard)];

        // No Express where nothing beats Standard — a local courier is often
        // both the cheapest and next-day, and a second, dearer row that
        // arrives no sooner is not a choice worth offering.
        if ($faster !== []) {
            $options[] = $this->option(self::EXPRESS, 'Express', $faster[0]);
        }

        return $options;
    }

    /**
     * The rates fit to offer, reduced to what choosing between them needs.
     *
     * @param  array<int, mixed>  $rates
     * @return array<int, array{cents: int, days: ?int, rate: array<string, mixed>}>
     */
    protected function usable(array $rates): array
    {
        $usable = [];

        foreach ($rates as $rate) {
            if (! is_array($rate)) {
                continue;
            }

            // Untracked post undercuts everything and leaves the customer's
            // order page with nothing to show. Stallion's own default is the
            // cheapest *tracked* service for the same reason.
            if (($rate['trackable'] ?? true) === false) {
                continue;
            }

            // The order is charged in CAD. A rate in anything else is a
            // number, not a price.
            if (strtoupper((string) ($rate['currency'] ?? 'CAD')) !== 'CAD') {
                continue;
            }

            // Postage plus surcharges, before tax — see the class comment.
            $cents = $this->toCents($rate['subtotal'] ?? null);

            if ($cents === null || $cents <= 0) {
                continue;
            }

            $days = $rate['estimated_delivery_days'] ?? null;

            $usable[] = [
                'cents' => $cents,
                'days' => is_numeric($days) && $days > 0 ? (int) $days : null,
                'rate' => $rate,
            ];
        }

        return $usable;
    }

    /** @param  array{cents: int, days: ?int, rate: array<string, mixed>}  $chosen */
    protected function option(string $code, string $name, array $chosen): ShippingOption
    {
        $rate = $chosen['rate'];

        return new ShippingOption(
            code: $code,
            name: $name,
            costCents: $chosen['cents'],
            carrier: $this->label($rate['carrier'] ?? null),
            service: $this->label($rate['service_name'] ?? null),
            deliveryDaysMin: $chosen['days'],
            deliveryDaysMax: $chosen['days'],
            provider: $this->code(),
            serviceCode: $this->label($rate['service'] ?? null),
        );
    }

    /** Somebody else's text, on its way into an order row: a string, and a short one. */
    protected function label(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 120, '');
    }

    /** Money crosses the wire as a decimal; it becomes cents immediately. */
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
     * The parcel as Stallion wants it described: one package, and its contents.
     *
     * Both are required. The contents are a customs line this shipment has no
     * use for — it never leaves Canada — so they are declared once, as a
     * whole, rather than product by product.
     *
     * @return array{packages: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>}
     */
    protected function shipment(Parcel $parcel): array
    {
        $weightKg = $this->weightKg($parcel);
        [$length, $width, $height] = $this->dimensionsCm($weightKg);

        return [
            'packages' => [[
                'weight' => $weightKg,
                'weight_unit' => 'kg',
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'size_unit' => 'cm',
            ]],
            'items' => [[
                'title' => 'Medical scrubs',
                'quantity' => 1,
                'value' => $this->declaredValue($parcel),
                'currency' => 'CAD',
            ]],
        ];
    }

    /**
     * A size for a parcel of this weight, because Stallion will not quote
     * without one and nothing in the catalogue records how big anything is.
     *
     * Carriers charge for whichever is greater, what a parcel weighs or the
     * space it takes up, so a size guessed too large is a price quoted too
     * high: one kilogram declared as a 45 cm cube comes back at five times
     * the rate. The parcel is therefore sized FROM its weight, at the density
     * of folded clothing in a mailer — which is also the density at which
     * most carriers stop charging by weight. The quote is, in effect, a quote
     * by weight. Packing loosely in big cartons will cost more than this says.
     *
     * @return array{0: float, 1: float, 2: float} Length, width, height.
     */
    protected function dimensionsCm(float $weightKg): array
    {
        $volume = $weightKg * max(1, (int) config('services.stallion.cm3_per_kg', 5000));

        // A mailer lying flat, 35 by 28, for as long as that is a sensible
        // shape. Rounded down, never up: up is how a parcel on a weight
        // boundary gets charged at the next one.
        $height = floor($volume / (35 * 28) * 10) / 10;

        if ($height <= 28) {
            return [35.0, 28.0, max(1.0, $height)];
        }

        // More than a mailer holds: a carton, as near a cube as makes no odds.
        $side = floor($volume ** (1 / 3) * 10) / 10;

        return [$side, $side, $side];
    }

    /**
     * What the contents are worth, within what Stallion will accept.
     *
     * Stallion refuses to rate anything declared above CAD 1,000, and a
     * wholesale order passes that easily. The figure does not move the price
     * — only insurance would, and none is asked for — so it is capped rather
     * than allowed to cost a large order its live rate.
     */
    protected function declaredValue(Parcel $parcel): float
    {
        return min(1000.0, max(0.01, round($parcel->subtotalCents / 100, 2)));
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
