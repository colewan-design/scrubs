<?php

namespace Tests\Feature;

use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductTierPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing engine is the commercial heart of the build and the place where
 * the requirements were most ambiguous. It carries the heaviest coverage:
 * tier boundaries, the oscillation case, guest visibility, and resolution order.
 */
class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(PricingService::class);
    }

    /** A $65 set, matching the brief's reference retail price. */
    protected function variant(int $retailCents = 6500): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => $retailCents]))
            ->create();
    }

    /** Tier 1: $200 spend -> $45/set, the brief's reference wholesale price. */
    protected function tier200(): PricingTier
    {
        return PricingTier::factory()->create([
            'name' => 'Tier 1',
            'min_subtotal_cents' => 20000,
            'min_qty' => null,
            'discount_type' => PricingTier::DISCOUNT_ABSOLUTE,
            'discount_value' => 4500,
            'priority' => 1,
        ]);
    }

    // ---------------------------------------------------------------- guests

    public function test_guest_sees_retail_pricing_only(): void
    {
        $this->tier200();
        $variant = $this->variant();

        // 4 x $65 = $260, comfortably over the $200 threshold.
        $quote = $this->pricing->quote([CartLine::make($variant, 4)], null);

        $this->assertFalse($quote->wholesaleVisible);
        $this->assertSame(26000, $quote->retailSubtotalCents);
        $this->assertSame(26000, $quote->subtotalCents, 'A guest must be charged retail.');
        $this->assertSame(0, $quote->discountCents);
        $this->assertNull($quote->tier);
    }

    public function test_qualifying_guest_is_told_the_saving_but_not_the_prices(): void
    {
        $this->tier200();
        $variant = $this->variant();

        $quote = $this->pricing->quote([CartLine::make($variant, 4)], null);
        $prompt = $quote->unlockPrompt();

        $this->assertTrue($quote->wouldQualifyIfSignedIn);
        $this->assertNotNull($prompt);
        // 4 sets: $260 retail vs $180 wholesale = $80 saving.
        $this->assertSame(8000, $prompt['saving_cents']);

        // The prompt must reveal the size of the saving, never the wholesale
        // prices themselves (§3).
        $this->assertArrayNotHasKey('unit_price_cents', $prompt);
        foreach ($quote->lines as $line) {
            $this->assertSame(6500, $line->unitPriceCents);
        }
    }

    public function test_guest_below_threshold_gets_no_prompt(): void
    {
        $this->tier200();
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 2)], null);

        $this->assertFalse($quote->wouldQualifyIfSignedIn);
        $this->assertNull($quote->unlockPrompt());
    }

    // --------------------------------------------------------- authenticated

    public function test_signed_in_customer_over_threshold_gets_wholesale(): void
    {
        $this->tier200();
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($this->variant(), 4)], $user);

        $this->assertTrue($quote->wholesaleVisible);
        $this->assertNotNull($quote->tier);
        $this->assertSame(26000, $quote->retailSubtotalCents);
        $this->assertSame(18000, $quote->subtotalCents);   // 4 x $45
        $this->assertSame(8000, $quote->discountCents);
    }

    public function test_signed_in_customer_below_threshold_pays_retail(): void
    {
        $this->tier200();
        $user = User::factory()->create();

        // §3: below the minimum they may still purchase at regular retail pricing.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 2)], $user);

        $this->assertNull($quote->tier);
        $this->assertSame(13000, $quote->subtotalCents);
        $this->assertSame(0, $quote->discountCents);
    }

    // ------------------------------------------------------------ boundaries

    public function test_exactly_at_the_threshold_qualifies(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 1000,
        ]);
        $user = User::factory()->create();

        // 4 x $50 = exactly $200.
        $quote = $this->pricing->quote([CartLine::make($this->variant(5000), 4)], $user);

        $this->assertNotNull($quote->tier, 'The threshold must be inclusive.');
        $this->assertSame(20000, $quote->retailSubtotalCents);
    }

    public function test_one_cent_below_the_threshold_does_not_qualify(): void
    {
        PricingTier::factory()->create(['min_subtotal_cents' => 20000]);
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($this->variant(19999), 1)], $user);

        $this->assertNull($quote->tier);
    }

    /**
     * The circular-pricing trap identified during requirements analysis.
     *
     * $210 retail qualifies -> wholesale drops it to $150 -> that is below the
     * $200 threshold -> un-qualifies back to $210 -> qualifies again...
     *
     * Qualification is evaluated on the PRE-DISCOUNT retail subtotal, so a
     * discount can never revoke the qualification that produced it.
     */
    public function test_discount_never_revokes_its_own_qualification(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_ABSOLUTE,
            'discount_value' => 5000,
        ]);
        $user = User::factory()->create();

        // 3 x $70 = $210 retail, which qualifies. At $50/unit the total becomes
        // $150 — below the threshold. The tier must still apply.
        $quote = $this->pricing->quote([CartLine::make($this->variant(7000), 3)], $user);

        $this->assertNotNull($quote->tier, 'Tier must survive its own discount.');
        $this->assertSame(21000, $quote->retailSubtotalCents);
        $this->assertSame(15000, $quote->subtotalCents);
        $this->assertSame(6000, $quote->discountCents);

        // Re-quoting the same cart must be stable, not oscillate.
        $again = $this->pricing->quote([CartLine::make($this->variant(7000), 3)], $user);
        $this->assertSame($quote->subtotalCents, $again->subtotalCents);
    }

    public function test_free_shipping_is_measured_on_the_retail_subtotal(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_ABSOLUTE,
            'discount_value' => 4500,
        ]);
        $user = User::factory()->create();

        // 10 x $65 = $650 retail (over the $600 threshold), but only $450 after
        // the wholesale discount. Free shipping must still apply — same rule.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 10)], $user);

        $this->assertSame(65000, $quote->retailSubtotalCents);
        $this->assertSame(45000, $quote->subtotalCents);
        $this->assertTrue($quote->qualifiesForFreeShipping);
        $this->assertSame(0, $quote->freeShippingGapCents);
    }

    // ----------------------------------------------------- qualification mode

    public function test_quantity_threshold_qualifies_independently_of_value(): void
    {
        // Open question Q3: tiers may qualify on units as well as dollars.
        PricingTier::factory()->create([
            'min_subtotal_cents' => 100000,   // $1000 — not reached
            'min_qty' => 10,                  // but 10 units is
            'qualify_mode' => 'any',
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 2000,
        ]);
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($this->variant(1000), 10)], $user);

        $this->assertNotNull($quote->tier, 'Either threshold should qualify in "any" mode.');
        $this->assertSame(8000, $quote->subtotalCents);   // 10 x $10 less 20%
    }

    public function test_all_mode_requires_both_thresholds(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 20000,
            'min_qty' => 10,
            'qualify_mode' => 'all',
        ]);
        $user = User::factory()->create();

        // $260 of value, but only 4 units — the quantity rule is not met.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 4)], $user);

        $this->assertNull($quote->tier);
    }

    public function test_a_tier_with_no_thresholds_never_matches(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => null,
            'min_qty' => null,
        ]);
        $user = User::factory()->create();

        // A misconfigured tier must not silently discount every cart.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 50)], $user);

        $this->assertNull($quote->tier);
    }

    // ------------------------------------------------------- resolution order

    public function test_highest_priority_qualifying_tier_wins(): void
    {
        PricingTier::factory()->create([
            'name' => 'Tier 1', 'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 1000, 'priority' => 1,
        ]);
        PricingTier::factory()->create([
            'name' => 'Tier 2', 'min_subtotal_cents' => 50000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 2000, 'priority' => 2,
        ]);
        $user = User::factory()->create();

        // $650 clears both; the better tier must win.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 10)], $user);

        $this->assertSame('Tier 2', $quote->tier->name);
        $this->assertSame(52000, $quote->subtotalCents);   // $650 less 20%
    }

    public function test_per_product_price_beats_the_tier_rule(): void
    {
        $tier = $this->tier200();     // absolute $45
        $variant = $this->variant();

        // This product is an exception to the catalogue-wide rule.
        ProductTierPrice::create([
            'product_id' => $variant->product_id,
            'pricing_tier_id' => $tier->id,
            'price_cents' => 4000,
        ]);
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($variant->fresh(), 4)], $user);

        $this->assertSame(16000, $quote->subtotalCents);   // 4 x $40, not $45
        $this->assertSame(
            PricingService::SOURCE_PRODUCT_TIER,
            $quote->lines->first()->priceSource
        );
    }

    public function test_wholesale_price_never_exceeds_retail(): void
    {
        // A misconfigured tier priced above retail must not raise the price.
        PricingTier::factory()->create([
            'min_subtotal_cents' => 1,
            'discount_type' => PricingTier::DISCOUNT_ABSOLUTE,
            'discount_value' => 9900,
        ]);
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($this->variant(6500), 1)], $user);

        $this->assertSame(6500, $quote->subtotalCents);
        $this->assertSame(0, $quote->discountCents);
    }

    public function test_variant_price_overrides_the_product_price(): void
    {
        $variant = $this->variant(6500);
        $variant->update(['retail_price_cents' => 7500]);

        $quote = $this->pricing->quote([CartLine::make($variant->fresh(), 2)], null);

        $this->assertSame(15000, $quote->retailSubtotalCents);
    }

    // ------------------------------------------------------------- next tier

    public function test_next_tier_reports_the_gap_and_what_reaching_it_saves(): void
    {
        PricingTier::factory()->create([
            'name' => 'Tier 1', 'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 1000, 'priority' => 1,
        ]);
        PricingTier::factory()->create([
            'name' => 'Tier 2', 'min_subtotal_cents' => 50000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 2000, 'priority' => 2,
        ]);
        $user = User::factory()->create();

        // $260: qualifies for Tier 1, $240 short of Tier 2.
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 4)], $user);

        $this->assertSame('Tier 1', $quote->tier->name);
        $this->assertSame('Tier 2', $quote->nextTier->name);
        $this->assertSame(24000, $quote->nextTierSubtotalGapCents);
        // This basket at 20% off ($208) versus at 10% off ($234) = $26 better.
        $this->assertSame(2600, $quote->nextTierSavingCents);
    }

    public function test_no_next_tier_when_the_top_tier_is_reached(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 20000, 'priority' => 1,
        ]);
        $user = User::factory()->create();

        $quote = $this->pricing->quote([CartLine::make($this->variant(), 10)], $user);

        $this->assertNull($quote->nextTier);
    }

    // ------------------------------------------------------------ edge cases

    public function test_empty_cart_produces_a_zero_quote(): void
    {
        $this->tier200();
        $quote = $this->pricing->quote([], User::factory()->create());

        $this->assertSame(0, $quote->retailSubtotalCents);
        $this->assertSame(0, $quote->subtotalCents);
        $this->assertSame(0, $quote->totalQty);
        $this->assertNull($quote->tier);
    }

    public function test_zero_quantity_lines_are_discarded(): void
    {
        $quote = $this->pricing->quote([CartLine::make($this->variant(), 0)], null);

        $this->assertCount(0, $quote->lines);
        $this->assertSame(0, $quote->retailSubtotalCents);
    }

    public function test_mixed_products_are_all_discounted_and_totals_reconcile(): void
    {
        $this->tier200();
        $user = User::factory()->create();

        $a = $this->variant(6500);
        $b = $this->variant(8000);

        // 2 x $65 + 2 x $80 = $290 retail, over the threshold.
        $quote = $this->pricing->quote([
            CartLine::make($a, 2),
            CartLine::make($b, 2),
        ], $user);

        $this->assertSame(29000, $quote->retailSubtotalCents);
        $this->assertSame(18000, $quote->subtotalCents);   // 4 units at the absolute $45
        $this->assertSame(4, $quote->totalQty);

        // Line totals must reconcile exactly against the header figures — no
        // rounding drift between the two.
        $this->assertSame(
            $quote->subtotalCents,
            $quote->lines->sum(fn ($l) => $l->lineTotalCents())
        );
        $this->assertSame(
            $quote->discountCents,
            $quote->lines->sum(fn ($l) => $l->lineDiscountCents())
        );
    }

    public function test_percentage_discounts_round_to_whole_cents(): void
    {
        PricingTier::factory()->create([
            'min_subtotal_cents' => 1,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 1500,      // 15%
        ]);
        $user = User::factory()->create();

        // $9.99 less 15% = $8.4915 -> must be a whole number of cents.
        $quote = $this->pricing->quote([CartLine::make($this->variant(999), 3)], $user);

        $unit = $quote->lines->first()->unitPriceCents;
        $this->assertSame(849, $unit);
        $this->assertIsInt($unit);
        $this->assertSame(2547, $quote->subtotalCents);
    }
}
