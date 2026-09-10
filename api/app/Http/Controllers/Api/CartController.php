<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoneyResource;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartService $carts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, $this->cart($request));
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $variant = ProductVariant::findOrFail($data['variant_id']);
        $cart = $this->carts->add($this->cart($request), $variant, $data['qty']);

        return $this->respond($request, $cart);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $variant = ProductVariant::findOrFail($data['variant_id']);
        $cart = $this->carts->updateQty($this->cart($request), $variant, $data['qty']);

        return $this->respond($request, $cart);
    }

    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
        ]);

        $variant = ProductVariant::findOrFail($data['variant_id']);
        $cart = $this->carts->remove($this->cart($request), $variant);

        return $this->respond($request, $cart);
    }

    protected function cart(Request $request): Cart
    {
        return $this->carts->resolve(
            $request->user(),
            $request->header('X-Cart-Token') ?: $request->input('cart_token')
        );
    }

    /**
     * Every money figure the cart displays originates here. The response also
     * carries the tier state that drives the wholesale-lock UI.
     */
    protected function respond(Request $request, Cart $cart): JsonResponse
    {
        $quote = $this->carts->quote($cart, $request->user());
        $cart->loadMissing(['items.variant.product.images', 'items.variant.color', 'items.variant.size']);

        $quoteByVariant = $quote->lines->keyBy(fn ($line) => $line->variant->id);

        $items = $cart->items->map(function ($item) use ($quoteByVariant) {
            $line = $quoteByVariant->get($item->product_variant_id);
            $variant = $item->variant;

            return [
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'product_slug' => $variant->product->slug,
                'sku' => $variant->sku,
                'variant_label' => $variant->displayName(),
                'image' => $variant->product->primaryImage()?->path,
                'qty' => $item->qty,
                'available' => $variant->availableStock(),
                'unit_retail' => MoneyResource::make($line?->unitRetailCents ?? $variant->retailPriceCents()),
                'unit_price' => MoneyResource::make($line?->unitPriceCents ?? $variant->retailPriceCents()),
                'line_total' => MoneyResource::make($line?->lineTotalCents() ?? 0),
                'line_discount' => MoneyResource::make($line?->lineDiscountCents() ?? 0),
            ];
        });

        $payload = $quote->toArray();

        // Preformatted display strings so the frontend never formats currency.
        $payload['totals'] = [
            'retail_subtotal' => MoneyResource::make($quote->retailSubtotalCents),
            'subtotal' => MoneyResource::make($quote->subtotalCents),
            'discount' => MoneyResource::make($quote->discountCents),
        ];

        if ($prompt = $quote->unlockPrompt()) {
            $payload['unlock_prompt']['saving'] = MoneyResource::make($prompt['saving_cents']);
        }

        if ($quote->nextTier) {
            $payload['next_tier']['subtotal_gap'] = MoneyResource::make($quote->nextTierSubtotalGapCents);
            $payload['next_tier']['additional_saving'] = MoneyResource::make($quote->nextTierSavingCents);
        }

        $payload['free_shipping']['gap'] = MoneyResource::make($quote->freeShippingGapCents);
        $payload['free_shipping']['threshold'] = MoneyResource::make($quote->freeShippingThresholdCents);

        return response()->json([
            'cart_token' => $cart->session_token,
            'item_count' => $cart->totalQty(),
            'items' => $items,
            'quote' => $payload,
        ]);
    }
}
