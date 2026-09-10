<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\PriceQuote;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Str;

/**
 * Server-side cart. Guest carts are keyed by an opaque token so they survive,
 * and merge into the account on login — which is what makes the "sign in to
 * unlock wholesale" moment work without the customer losing their basket.
 */
class CartService
{
    public function __construct(
        protected PricingService $pricing,
    ) {}

    public function resolve(?User $user, ?string $token): Cart
    {
        if ($user) {
            $cart = Cart::firstOrCreate(['user_id' => $user->id]);

            // A guest built a basket, then signed in — bring it with them.
            if ($token) {
                $guestCart = Cart::where('session_token', $token)->whereNull('user_id')->first();

                if ($guestCart && $guestCart->isNot($cart)) {
                    $this->merge($guestCart, $cart);
                }
            }

            return $cart;
        }

        if ($token) {
            $cart = Cart::where('session_token', $token)->whereNull('user_id')->first();

            if ($cart) {
                return $cart;
            }
        }

        return Cart::create([
            'session_token' => $token ?: Str::random(48),
            'expires_at' => now()->addDays(30),
        ]);
    }

    /** Quantities are summed, not replaced, so nothing is silently lost. */
    protected function merge(Cart $from, Cart $into): void
    {
        foreach ($from->items as $item) {
            $existing = $into->items()->where('product_variant_id', $item->product_variant_id)->first();

            if ($existing) {
                $existing->update(['qty' => $existing->qty + $item->qty]);
            } else {
                $into->items()->create([
                    'product_variant_id' => $item->product_variant_id,
                    'qty' => $item->qty,
                ]);
            }
        }

        $from->items()->delete();
        $from->delete();
        $into->load('items');
    }

    public function add(Cart $cart, ProductVariant $variant, int $qty): Cart
    {
        $existing = $cart->items()->where('product_variant_id', $variant->id)->first();
        $requested = ($existing?->qty ?? 0) + $qty;

        // Never let the cart exceed what can actually be sold (§2).
        $allowed = min($requested, $variant->availableStock());

        if ($allowed < 1) {
            return $cart->fresh('items');
        }

        if ($existing) {
            $existing->update(['qty' => $allowed]);
        } else {
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $allowed]);
        }

        return $cart->fresh('items');
    }

    public function updateQty(Cart $cart, ProductVariant $variant, int $qty): Cart
    {
        $item = $cart->items()->where('product_variant_id', $variant->id)->first();

        if (! $item) {
            return $cart->fresh('items');
        }

        if ($qty < 1) {
            $item->delete();

            return $cart->fresh('items');
        }

        $item->update(['qty' => min($qty, $variant->availableStock())]);

        return $cart->fresh('items');
    }

    public function remove(Cart $cart, ProductVariant $variant): Cart
    {
        $cart->items()->where('product_variant_id', $variant->id)->delete();

        return $cart->fresh('items');
    }

    /**
     * Price the cart. This is the only source of the numbers the cart displays —
     * the frontend performs no arithmetic of its own.
     */
    public function quote(Cart $cart, ?User $user): PriceQuote
    {
        $cart->loadMissing(['items.variant.product.tierPrices', 'items.variant.color', 'items.variant.size']);

        $lines = $cart->items
            ->filter(fn ($item) => $item->variant && $item->variant->is_active)
            ->map(fn ($item) => CartLine::make($item->variant, $item->qty))
            ->values();

        return $this->pricing->quote($lines, $user);
    }
}
