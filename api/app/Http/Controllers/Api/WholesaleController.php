<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoneyResource;
use App\Models\PricingTier;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Powers the "Wholesale / How It Works" page (§11) — the page that converts a
 * browser into an account, so the tier ladder is real data, never hardcoded copy.
 *
 * SECURITY: the thresholds are public (they are the pitch), but the prices are
 * not. §3 requires wholesale pricing hidden from anyone not signed in, and
 * hiding it in the template is not enough — anything returned here ends up in
 * the server-rendered payload and is readable in view-source. So the price is
 * omitted from the response entirely for guests.
 */
class WholesaleController extends Controller
{
    public function __invoke(Request $request, Settings $settings): JsonResponse
    {
        $unlocked = $request->user() !== null;

        $tiers = PricingTier::query()
            ->active()
            ->orderBy('priority')
            ->get()
            ->map(function (PricingTier $tier) use ($unlocked) {
                $row = [
                    'name' => $tier->name,
                    'slug' => $tier->slug,
                    'description' => $tier->description,
                    // Thresholds are public — they are what persuades someone to sign up.
                    'min_subtotal' => MoneyResource::make($tier->min_subtotal_cents),
                    'min_qty' => $tier->min_qty,
                    'qualify_mode' => $tier->qualify_mode,
                    'locked' => ! $unlocked,
                ];

                // Prices are only ever serialised for an authenticated user.
                if ($unlocked) {
                    $row['unit_price'] = $tier->discount_type === PricingTier::DISCOUNT_ABSOLUTE
                        ? MoneyResource::make($tier->discount_value)
                        : null;
                    $row['discount_percent'] = $tier->discount_type === PricingTier::DISCOUNT_PERCENT
                        ? $tier->discount_value / 100
                        : null;
                }

                return $row;
            });

        return response()->json([
            'tiers' => $tiers,
            'wholesale_unlocked' => $unlocked,
            'min_order' => MoneyResource::make($settings->minWholesaleOrderCents()),
            'free_shipping_threshold' => MoneyResource::make($settings->freeShippingThresholdCents()),
        ]);
    }
}
