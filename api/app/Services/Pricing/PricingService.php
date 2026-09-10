<?php

namespace App\Services\Pricing;

use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * The single place prices are decided — requirements §3.
 *
 * Used by the cart, by checkout, and by order creation alike. Nothing else may
 * calculate a price, and the frontend never calculates money at all.
 *
 * THREE RULES THIS CLASS MUST NEVER BREAK
 *
 *  1. Tier qualification is evaluated against the PRE-DISCOUNT RETAIL subtotal.
 *     Evaluating against the discounted total lets a cart oscillate: $210
 *     qualifies, the discount drops it to $150, that falls below the threshold,
 *     it un-qualifies back to $210, and round again. A discount must never
 *     revoke the qualification that produced it. The same rule governs the
 *     free-shipping threshold.
 *
 *  2. Wholesale prices are never returned to a guest. §3 requires them hidden
 *     from anyone not signed in. A qualifying guest is told a saving exists and
 *     how large it is — never the wholesale prices themselves.
 *
 *  3. Prices are re-quoted at order creation and snapshotted onto order_items.
 *     A cart left open for two days must not be able to charge stale prices.
 */
class PricingService
{
    public const SOURCE_RETAIL = 'retail';
    public const SOURCE_PRICE_LIST = 'customer_price_list';
    public const SOURCE_PRODUCT_TIER = 'product_tier_price';
    public const SOURCE_TIER_RULE = 'tier_rule';

    public function __construct(
        protected Settings $settings,
    ) {}

    /**
     * @param  Collection<int, CartLine>|array<int, CartLine>  $lines
     */
    public function quote(Collection|array $lines, ?User $user = null): PriceQuote
    {
        $lines = collect($lines)->filter(fn (CartLine $l) => $l->qty > 0)->values();

        // --- 1. Retail baseline. Everything qualifies off these numbers. ---
        $retailSubtotalCents = 0;
        $totalQty = 0;

        foreach ($lines as $line) {
            $retailSubtotalCents += $line->variant->retailPriceCents() * $line->qty;
            $totalQty += $line->qty;
        }

        // --- 2. Visibility. Wholesale is only ever shown to an authenticated user. ---
        $wholesaleVisible = $user !== null;

        // --- 3. Tier matching, always against the retail subtotal (rule 1). ---
        $tiers = $this->activeTiers();
        $matchedTier = $this->matchTier($tiers, $retailSubtotalCents, $totalQty);

        // A guest never receives wholesale prices, but we still work out whether
        // they *would* qualify so the cart can prompt them to sign in.
        $effectiveTier = $wholesaleVisible ? $matchedTier : null;

        // --- 4. Resolve each line's unit price. ---
        $quotedLines = $lines->map(
            fn (CartLine $line) => $this->quoteLine($line, $effectiveTier, $user)
        );

        $subtotalCents = $quotedLines->sum(fn (QuotedLine $l) => $l->lineTotalCents());
        $discountCents = $retailSubtotalCents - $subtotalCents;

        // --- 5. What a guest would save by signing in. ---
        $potentialSavingCents = null;
        if (! $wholesaleVisible && $matchedTier !== null) {
            $hypothetical = $lines->map(
                fn (CartLine $line) => $this->quoteLine($line, $matchedTier, $user)
            );
            $potentialSavingCents = $retailSubtotalCents
                - $hypothetical->sum(fn (QuotedLine $l) => $l->lineTotalCents());
        }

        // --- 6. The next tier up, and what reaching it is worth. ---
        [$nextTier, $nextSubtotalGap, $nextQtyGap, $nextSaving] =
            $this->resolveNextTier($tiers, $matchedTier, $lines, $retailSubtotalCents, $totalQty, $user);

        // --- 7. Free shipping, also measured on the retail subtotal (rule 1). ---
        $freeShippingThreshold = $this->settings->freeShippingThresholdCents();
        $qualifiesFreeShipping = $retailSubtotalCents >= $freeShippingThreshold;

        return new PriceQuote(
            lines: $quotedLines,
            retailSubtotalCents: $retailSubtotalCents,
            subtotalCents: $subtotalCents,
            discountCents: $discountCents,
            totalQty: $totalQty,
            tier: $effectiveTier,
            nextTier: $nextTier,
            nextTierSubtotalGapCents: $nextSubtotalGap,
            nextTierQtyGap: $nextQtyGap,
            nextTierSavingCents: $nextSaving,
            wholesaleVisible: $wholesaleVisible,
            wouldQualifyIfSignedIn: ! $wholesaleVisible && $matchedTier !== null,
            potentialSavingCents: $potentialSavingCents,
            freeShippingThresholdCents: $freeShippingThreshold,
            freeShippingGapCents: max(0, $freeShippingThreshold - $retailSubtotalCents),
            qualifiesForFreeShipping: $qualifiesFreeShipping,
        );
    }

    /**
     * Resolution order for a single line. First match wins:
     *   customer price list → per-product tier price → tier discount rule → retail.
     */
    protected function quoteLine(CartLine $line, ?PricingTier $tier, ?User $user): QuotedLine
    {
        $variant = $line->variant;
        $retailUnit = $variant->retailPriceCents();

        // (a) Customer-specific price list — the §13 seam. Beats every tier.
        if ($user?->customer_price_list_id) {
            $listPrice = $this->customerPriceFor($user, $variant->product_id, $line->qty);

            if ($listPrice !== null) {
                return new QuotedLine(
                    $variant, $line->qty, $retailUnit,
                    min($listPrice, $retailUnit), self::SOURCE_PRICE_LIST
                );
            }
        }

        if ($tier === null) {
            return new QuotedLine($variant, $line->qty, $retailUnit, $retailUnit, self::SOURCE_RETAIL);
        }

        // (b) Per-product wholesale price — the "$65 retail / $45 wholesale" shape.
        // (c) Otherwise the tier's catalogue-wide discount rule. Both live in
        //     tierUnitPrice(), so the ladder on the product page and the price
        //     in the cart can never disagree about the same tier.
        $productPrice = $variant->product
            ->tierPrices
            ->firstWhere('pricing_tier_id', $tier->id);

        return new QuotedLine(
            $variant,
            $line->qty,
            $retailUnit,
            $this->tierUnitPrice($variant->product, $retailUnit, $tier),
            $productPrice ? self::SOURCE_PRODUCT_TIER : self::SOURCE_TIER_RULE
        );
    }

    /**
     * What one unit of a product costs at one tier. Per-product price first,
     * the tier's discount rule second, and never above retail.
     */
    protected function tierUnitPrice(Product $product, int $retailUnitCents, PricingTier $tier): int
    {
        $productPrice = $product->tierPrices->firstWhere('pricing_tier_id', $tier->id);

        return min(
            $productPrice ? $productPrice->price_cents : $tier->applyTo($retailUnitCents),
            $retailUnitCents
        );
    }

    /**
     * The price ladder for one product: what a unit costs at each active tier.
     *
     * The product page shows this to a signed-in shopper so the tiers are
     * concrete before anything is in the cart — §3's "unlock" has to resolve
     * into real numbers, not merely the absence of a lock.
     *
     * Rule 2 above still governs: this returns an empty ladder when there is no
     * user, so a mistake in a resource cannot leak wholesale prices to a guest.
     *
     * A customer price list (§13) is deliberately NOT applied here. Those
     * prices depend on line quantity and are resolved per line at cart time;
     * quoting one here would promise a number the cart might not honour.
     *
     * @return array<int, array{tier: PricingTier, unit_price_cents: int, saving_cents: int}>
     */
    public function ladderFor(Product $product, ?User $user = null): array
    {
        if ($user === null) {
            return [];
        }

        $retailUnit = $product->retail_price_cents;

        return $this->activeTiers()
            ->sortBy('priority')
            ->map(function (PricingTier $tier) use ($product, $retailUnit) {
                $unit = $this->tierUnitPrice($product, $retailUnit, $tier);

                return [
                    'tier' => $tier,
                    'unit_price_cents' => $unit,
                    'saving_cents' => $retailUnit - $unit,
                ];
            })
            // A tier that saves nothing on this product is noise on the page.
            ->filter(fn (array $rung) => $rung['saving_cents'] > 0)
            ->values()
            ->all();
    }

    protected function customerPriceFor(User $user, int $productId, int $qty): ?int
    {
        $item = $user->customerPriceList?->items
            ->where('product_id', $productId)
            ->filter(fn ($i) => $i->min_qty === null || $qty >= $i->min_qty)
            ->sortByDesc('min_qty')
            ->first();

        return $item?->price_cents;
    }

    /** The highest-priority tier this cart qualifies for. */
    protected function matchTier(Collection $tiers, int $retailSubtotalCents, int $totalQty): ?PricingTier
    {
        return $tiers
            ->filter(fn (PricingTier $t) => $t->qualifies($retailSubtotalCents, $totalQty))
            ->sortByDesc('priority')
            ->first();
    }

    /**
     * The cheapest not-yet-reached tier above the current one, with the gap to
     * it and what reaching it would save. Powers the strongest merchandising
     * moment on the site: "Add $46 more to unlock Tier 2 and save $120."
     */
    protected function resolveNextTier(
        Collection $tiers,
        ?PricingTier $current,
        Collection $lines,
        int $retailSubtotalCents,
        int $totalQty,
        ?User $user,
    ): array {
        $candidates = $tiers
            ->filter(fn (PricingTier $t) => ! $t->qualifies($retailSubtotalCents, $totalQty))
            ->when($current !== null, fn ($c) => $c->filter(
                fn (PricingTier $t) => $t->priority > $current->priority
            ))
            // Nearest first: the smallest additional spend to reach.
            ->sortBy(fn (PricingTier $t) => $t->min_subtotal_cents ?? PHP_INT_MAX);

        $next = $candidates->first();

        if ($next === null) {
            return [null, null, null, null];
        }

        $gap = $next->gapFrom($retailSubtotalCents, $totalQty);

        // What the current basket would cost at the next tier — the honest
        // saving figure, not a projection of a larger basket.
        $atNextTier = $lines
            ->map(fn (CartLine $line) => $this->quoteLine($line, $next, $user))
            ->sum(fn (QuotedLine $l) => $l->lineTotalCents());

        $currentTotal = $lines
            ->map(fn (CartLine $line) => $this->quoteLine($line, $current, $user))
            ->sum(fn (QuotedLine $l) => $l->lineTotalCents());

        return [
            $next,
            $gap['subtotal_cents'],
            $gap['qty'],
            max(0, $currentTotal - $atNextTier),
        ];
    }

    /** @return Collection<int, PricingTier> */
    protected function activeTiers(): Collection
    {
        return PricingTier::query()->active()->orderByDesc('priority')->get();
    }
}
