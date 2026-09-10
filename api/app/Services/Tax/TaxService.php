<?php

namespace App\Services\Tax;

use App\Models\TaxRate;
use Illuminate\Support\Collection;

/**
 * Canadian sales tax (§6).
 *
 * Resolved by DESTINATION province, which is the rule for goods shipped within
 * Canada — where the business is located does not enter into it.
 *
 * THREE RULES THIS CLASS MUST NEVER BREAK
 *
 *  1. Taxes are never compounded. GST and PST both apply to the pre-tax base;
 *     tax is not charged on tax. This has been true of QST in Quebec since
 *     2013, and was the last exception in the country.
 *
 *  2. Whether shipping is taxable is a data question, not a code question. A
 *     rate row carries `applies_to` = goods or shipping, so a province that
 *     taxes freight differently is a row, not a branch in here.
 *
 *  3. Rate rows are effective-dated and looked up as at a given date, so
 *     reprinting a two-year-old invoice reproduces the tax actually charged
 *     rather than today's rate.
 *
 * Which provinces the client is REGISTERED to collect in is a business decision
 * for their accountant (Q6). PST and QST rows ship inactive for that reason;
 * an administrator switches them on as registration completes.
 */
class TaxService
{
    public const APPLIES_GOODS = 'goods';
    public const APPLIES_SHIPPING = 'shipping';

    /**
     * @param  string|null  $province  Two-letter destination code. Null means an
     *                                 unknown destination, which is never taxed —
     *                                 checkout collects the address first.
     * @param  string|null  $asOf      Date to price the rates at; defaults to today.
     */
    public function calculate(
        ?string $province,
        int $goodsCents,
        int $shippingCents = 0,
        ?string $asOf = null,
    ): TaxQuote {
        if ($province === null || $province === '') {
            return TaxQuote::none($province);
        }

        $province = strtoupper($province);

        $rates = $this->ratesFor($province, $asOf);

        if ($rates->isEmpty()) {
            return TaxQuote::none($province);
        }

        // Accumulate by (tax_type, rate) so a province whose goods and shipping
        // rates are identical — the normal case — prints one line, while a
        // genuine difference in rate still itemises separately.
        $buckets = [];

        foreach ($rates as $rate) {
            $base = $rate->applies_to === self::APPLIES_SHIPPING ? $shippingCents : $goodsCents;

            if ($base <= 0) {
                continue;
            }

            $key = $rate->tax_type.'|'.$rate->rate_bps;

            $buckets[$key] ??= [
                'tax_type' => $rate->tax_type,
                'rate_bps' => (int) $rate->rate_bps,
                'base' => 0,
            ];

            $buckets[$key]['base'] += $base;
        }

        $lines = [];

        foreach ($buckets as $bucket) {
            // Rounded once, on the combined base — rounding each component
            // separately and adding them drifts by a cent on some totals.
            $amount = (int) round($bucket['base'] * $bucket['rate_bps'] / 10000);

            $lines[] = new TaxLine(
                taxType: $bucket['tax_type'],
                province: $province,
                rateBps: $bucket['rate_bps'],
                taxableBaseCents: $bucket['base'],
                amountCents: $amount,
            );
        }

        return new TaxQuote($lines, $province);
    }

    /** @return Collection<int, TaxRate> */
    protected function ratesFor(string $province, ?string $asOf): Collection
    {
        return TaxRate::query()
            ->active()
            ->effectiveOn($asOf)
            ->where('province', $province)
            ->orderBy('tax_type')
            ->get();
    }

    /**
     * The provinces tax is actually collected in. Checkout uses this to keep the
     * destination dropdown honest rather than offering somewhere that would
     * silently be untaxed.
     *
     * @return array<int, string>
     */
    public function collectingProvinces(?string $asOf = null): array
    {
        return TaxRate::query()
            ->active()
            ->effectiveOn($asOf)
            ->distinct()
            ->orderBy('province')
            ->pluck('province')
            ->all();
    }
}
