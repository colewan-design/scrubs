<?php

namespace Database\Seeders;

use App\Models\Color;
use App\Models\PricingTier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Setting;
use App\Models\Size;
use App\Models\TaxRate;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * Reference data the application needs to function: sizes, colours, tax rates,
 * settings, and placeholder wholesale tiers.
 *
 * The tier numbers here are PLACEHOLDERS built from the brief's reference
 * figures ($200 MOQ, ~$65 retail, ~$45 wholesale). The client's final three-tier
 * thresholds and prices are still outstanding — see docs/06-open-questions.md Q3.
 * All of it is editable from the admin dashboard, so replacing these is a
 * settings change and not a deployment.
 */
class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $this->sizes();
        $this->colors();
        $this->taxRates();
        $this->settings();
        $this->pricingTiers();
        $this->shippingRates();
    }

    /** Position drives sort order — sizes must never sort alphabetically. */
    protected function sizes(): void
    {
        $sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];

        foreach ($sizes as $i => $name) {
            Size::updateOrCreate(
                ['slug' => strtolower($name)],
                ['name' => $name, 'position' => $i, 'is_active' => true]
            );
        }
    }

    /**
     * Placeholder palette — the client's final colour list is outstanding (§14).
     *
     * The second block exists because CatalogSeeder only builds a variant for a
     * colour it can show a photograph of. Those names therefore have to match
     * the colourways in web/public/placeholders/products.json exactly; renaming
     * one here silently drops it from the seeded catalogue.
     */
    protected function colors(): void
    {
        $colors = [
            ['Ceil Blue', '#8FA9C4'],
            ['Navy', '#2C3A52'],
            ['Black', '#1A1A1A'],
            ['Wine', '#6B2C39'],
            ['Hunter Green', '#3A5A46'],
            ['Pewter', '#8A8D8F'],
            ['Caribbean Blue', '#4C9BAF'],
            ['Royal Blue', '#2B4C8C'],

            // Image-backed colourways.
            ['Charcoal', '#4B4B4F'],
            ['Dark Harbor', '#33414F'],
            ['Sagewater', '#A3B5AB'],
            ['Moss', '#5F6B4A'],
            ['Smokey Taupe', '#9B8981'],
            ['Burgundy', '#6E2639'],
            ['Jade', '#2E7D6B'],
            ['Purple Haze', '#8577A0'],
            ['Lagoon', '#2F7C8F'],
            ['Silver Frost', '#C9D2D6'],
            ['Heather Light Grey', '#C2C6C9'],
            ['Heather Dark Charcoal', '#55575B'],
        ];

        foreach ($colors as $i => [$name, $hex]) {
            Color::updateOrCreate(
                ['slug' => str($name)->slug()->value()],
                ['name' => $name, 'hex' => $hex, 'position' => $i, 'is_active' => true]
            );
        }
    }

    /**
     * Canadian sales tax by destination province (§6).
     *
     * GST/HST is seeded active for every jurisdiction. PST (BC/SK/MB) and QST
     * are seeded INACTIVE, because collecting them depends on the client being
     * registered — a business decision for their accountant, not a code
     * decision (open question Q6). The admin switches them on as they register.
     */
    protected function taxRates(): void
    {
        $hst = [
            'ON' => 1300, 'NB' => 1500, 'NL' => 1500,
            'NS' => 1400, 'PE' => 1500,
        ];

        $gstOnly = ['AB', 'BC', 'MB', 'NT', 'NU', 'QC', 'SK', 'YT'];

        foreach ($hst as $province => $rate) {
            TaxRate::updateOrCreate(
                ['province' => $province, 'tax_type' => 'HST', 'applies_to' => 'goods'],
                ['rate_bps' => $rate, 'is_active' => true]
            );
        }

        foreach ($gstOnly as $province) {
            TaxRate::updateOrCreate(
                ['province' => $province, 'tax_type' => 'GST', 'applies_to' => 'goods'],
                ['rate_bps' => 500, 'is_active' => true]
            );
        }

        // Freight is taxable at the destination's GST/HST rate. Without these
        // rows shipping is silently untaxed, which under-collects on every
        // delivered order. PST/QST on freight is left off alongside their goods
        // rows, pending the registration answer (Q6).
        foreach ($hst as $province => $rate) {
            TaxRate::updateOrCreate(
                ['province' => $province, 'tax_type' => 'HST', 'applies_to' => 'shipping'],
                ['rate_bps' => $rate, 'is_active' => true]
            );
        }

        foreach ($gstOnly as $province) {
            TaxRate::updateOrCreate(
                ['province' => $province, 'tax_type' => 'GST', 'applies_to' => 'shipping'],
                ['rate_bps' => 500, 'is_active' => true]
            );
        }

        // Provincial taxes — seeded off pending registration confirmation.
        $provincial = [
            ['BC', 'PST', 700],
            ['SK', 'PST', 600],
            ['MB', 'PST', 700],
            ['QC', 'QST', 998],
        ];

        foreach ($provincial as [$province, $type, $rate]) {
            TaxRate::updateOrCreate(
                ['province' => $province, 'tax_type' => $type, 'applies_to' => 'goods'],
                ['rate_bps' => $rate, 'is_active' => false]
            );
        }
    }

    protected function settings(): void
    {
        foreach (Settings::DEFAULTS as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => explode('.', $key)[0]]
            );
        }

        app(Settings::class)->flush();
    }

    /**
     * PLACEHOLDER tiers, pending Q3. Both a dollar threshold and a unit
     * threshold are set on each with qualify_mode 'any', so either qualifies —
     * the recommended default.
     *
     * Percentages, not absolute prices. The brief expresses wholesale as
     * "~$45 per scrub set", but an absolute price is a per-PRODUCT figure: as a
     * catalogue-wide rule it silently does nothing to every product cheaper
     * than $45 (applyTo() never returns more than retail), which is most of the
     * catalogue. A percentage is the only discount type that behaves sanely
     * across a mixed catalogue. Per-product wholesale prices, when the client
     * supplies them, belong in `product_tier_prices`, which takes precedence.
     *
     * 30% at Tier 1 is not a guess: it reproduces the reference figure already
     * seeded on every product (`wholesale_base_price_cents` = 0.7 x retail), so
     * the catalogue stays self-consistent. The 35/40% steps ARE placeholders —
     * the real ladder is a client decision.
     */
    protected function pricingTiers(): void
    {
        $tiers = [
            ['Tier 1', 'tier-1', 20000, 4, 3000, 1],
            ['Tier 2', 'tier-2', 60000, 12, 3500, 2],
            ['Tier 3', 'tier-3', 150000, 30, 4000, 3],
        ];

        foreach ($tiers as [$name, $slug, $minSubtotal, $minQty, $discountBps, $priority]) {
            PricingTier::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'min_subtotal_cents' => $minSubtotal,
                    'min_qty' => $minQty,
                    'qualify_mode' => 'any',
                    'discount_type' => PricingTier::DISCOUNT_PERCENT,
                    'discount_value' => $discountBps,
                    'priority' => $priority,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * PLACEHOLDER rates, pending the client's own table (materials request §6.1).
     *
     * One catch-all Canadian zone, banded by order value rather than weight,
     * because per-variant weights are outstanding client data (Risk R4) and a
     * weight-banded table would price every order at zero grams until they
     * arrive. Once weights land, weight bands can be added without touching
     * code — that is what the zone/rate tables are for.
     *
     * Free shipping is NOT expressed as a rate row. It comes from
     * `shipping.free_threshold_cents`, the same setting the cart advertises to
     * the shopper, so the promise and the charge cannot drift apart.
     */
    protected function shippingRates(): void
    {
        $zone = ShippingZone::updateOrCreate(
            ['name' => 'Canada'],
            ['provinces' => [], 'position' => 0, 'is_active' => true]
        );

        $rates = [
            ['Standard', 1500, 3, 7, 0],
            ['Express', 2900, 1, 3, 1],
        ];

        foreach ($rates as [$name, $cents, $daysMin, $daysMax, $position]) {
            ShippingRate::updateOrCreate(
                ['shipping_zone_id' => $zone->id, 'name' => $name],
                [
                    'rate_cents' => $cents,
                    'is_free' => false,
                    'delivery_days_min' => $daysMin,
                    'delivery_days_max' => $daysMax,
                    'position' => $position,
                    'is_active' => true,
                ]
            );
        }
    }
}
