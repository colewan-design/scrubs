<?php

namespace App\Services\Pricing;

use App\Models\PricingTier;
use Illuminate\Support\Collection;

/**
 * The result of pricing a set of cart lines. Immutable.
 *
 * This is the ONLY thing the storefront is allowed to display money from.
 * The frontend never computes subtotals, discounts, tax, or the free-shipping
 * gap — every figure it shows came from here.
 */
final class PriceQuote
{
    /**
     * @param  Collection<int, QuotedLine>  $lines
     */
    public function __construct(
        public readonly Collection $lines,
        public readonly int $retailSubtotalCents,
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $totalQty,
        public readonly ?PricingTier $tier,
        public readonly ?PricingTier $nextTier,
        public readonly ?int $nextTierSubtotalGapCents,
        public readonly ?int $nextTierQtyGap,
        public readonly ?int $nextTierSavingCents,
        public readonly bool $wholesaleVisible,
        public readonly bool $wouldQualifyIfSignedIn,
        public readonly ?int $potentialSavingCents,
        public readonly int $freeShippingThresholdCents,
        public readonly int $freeShippingGapCents,
        public readonly bool $qualifiesForFreeShipping,
    ) {}

    public function hasWholesalePricing(): bool
    {
        return $this->tier !== null && $this->discountCents > 0;
    }

    /**
     * The signup prompt for a guest whose cart has crossed the threshold.
     * The highest-intent moment in the funnel — a guest gets the fact that a
     * saving exists and its size, but never the wholesale prices themselves.
     */
    public function unlockPrompt(): ?array
    {
        if ($this->wholesaleVisible || ! $this->wouldQualifyIfSignedIn) {
            return null;
        }

        return [
            'qualifies' => true,
            'saving_cents' => $this->potentialSavingCents,
        ];
    }

    public function toArray(): array
    {
        return [
            'lines' => $this->lines->map(fn (QuotedLine $l) => $l->toArray())->all(),
            'retail_subtotal_cents' => $this->retailSubtotalCents,
            'subtotal_cents' => $this->subtotalCents,
            'discount_cents' => $this->discountCents,
            'total_qty' => $this->totalQty,
            'currency' => 'CAD',
            'tier' => $this->tier ? [
                'id' => $this->tier->id,
                'name' => $this->tier->name,
                'slug' => $this->tier->slug,
            ] : null,
            'next_tier' => $this->nextTier ? [
                'id' => $this->nextTier->id,
                'name' => $this->nextTier->name,
                'subtotal_gap_cents' => $this->nextTierSubtotalGapCents,
                'qty_gap' => $this->nextTierQtyGap,
                'additional_saving_cents' => $this->nextTierSavingCents,
            ] : null,
            'wholesale_visible' => $this->wholesaleVisible,
            'unlock_prompt' => $this->unlockPrompt(),
            'free_shipping' => [
                'threshold_cents' => $this->freeShippingThresholdCents,
                'gap_cents' => $this->freeShippingGapCents,
                'qualifies' => $this->qualifiesForFreeShipping,
            ],
        ];
    }
}
