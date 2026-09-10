<?php

namespace Tests\Feature;

use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §3 requires wholesale pricing hidden from anyone not signed in.
 *
 * Hiding it in the template is NOT enough: anything the API returns ends up in
 * the server-rendered payload and is readable in view-source. These tests assert
 * on the raw response body, so a leak fails the build rather than shipping.
 */
class WholesaleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function seedTier(): PricingTier
    {
        return PricingTier::factory()->create([
            'name' => 'Tier 1',
            'slug' => 'tier-1',
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_ABSOLUTE,
            'discount_value' => 4500,   // $45.00 — must never reach a guest
        ]);
    }

    public function test_wholesale_endpoint_hides_prices_from_guests(): void
    {
        $this->seedTier();

        $response = $this->getJson('/api/v1/wholesale')->assertOk();

        // Thresholds are public — they are the pitch.
        $response->assertJsonPath('tiers.0.min_subtotal.formatted', '$200.00');
        $response->assertJsonPath('tiers.0.locked', true);
        $response->assertJsonPath('wholesale_unlocked', false);

        // The price must be absent from the payload entirely, not merely hidden
        // by the frontend.
        $response->assertJsonMissingPath('tiers.0.unit_price');
        $this->assertStringNotContainsString('45.00', $response->getContent());
        $this->assertStringNotContainsString('4500', $response->getContent());
    }

    public function test_wholesale_endpoint_reveals_prices_once_signed_in(): void
    {
        $this->seedTier();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/wholesale')
            ->assertOk();

        $response->assertJsonPath('tiers.0.unit_price.formatted', '$45.00');
        $response->assertJsonPath('tiers.0.locked', false);
        $response->assertJsonPath('wholesale_unlocked', true);
    }

    public function test_product_listing_hides_wholesale_from_guests(): void
    {
        ProductVariant::factory()->for(
            Product::factory()->state([
                'retail_price_cents' => 6500,
                'wholesale_base_price_cents' => 4500,
            ])
        )->create();

        $response = $this->getJson('/api/v1/products')->assertOk();

        $response->assertJsonPath('data.0.wholesale_locked', true);
        $response->assertJsonMissingPath('data.0.wholesale_from');
        $this->assertStringNotContainsString('45.00', $response->getContent());
    }

    public function test_product_listing_reveals_wholesale_to_members(): void
    {
        $this->seedTier();
        ProductVariant::factory()->for(
            Product::factory()->state([
                'retail_price_cents' => 6500,
                'wholesale_base_price_cents' => 4500,
            ])
        )->create();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/products')
            ->assertOk();

        $response->assertJsonPath('data.0.wholesale_locked', false);
        $response->assertJsonPath('data.0.wholesale_from.formatted', '$45.00');
    }

    /**
     * The figure a grid advertises must be one PricingService will actually
     * charge. It used to be read straight off `wholesale_base_price_cents`, a
     * reference column no part of the pricing engine consults — so the grid
     * quoted $45 on a product the cart then rang up at full retail.
     */
    public function test_advertised_wholesale_price_is_the_price_the_cart_charges(): void
    {
        // A tier expressed the way the brief expresses wholesale: an absolute
        // per-set price. On anything cheaper than that price it is a no-op.
        $this->seedTier();

        $variant = ProductVariant::factory()->for(
            Product::factory()->state([
                'retail_price_cents' => 3800,        // cheaper than the $45 tier price
                'wholesale_base_price_cents' => 2660, // the reference figure
            ])
        )->create(['stock_qty' => 100]);

        $user = User::factory()->create();

        $card = $this->actingAs($user)->getJson('/api/v1/products')->assertOk();

        // 10 x $38 = $380, past the $200 threshold.
        $cart = $this->actingAs($user)
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => 10])
            ->assertOk();

        $advertised = $card->json('data.0.wholesale_from.cents');
        $charged = $cart->json('items.0.unit_price.cents');

        // No saving is reachable here, so nothing may be advertised. What must
        // never happen is a figure being shown that the cart does not honour.
        $this->assertNull($advertised);
        $this->assertSame(3800, $charged);
    }

    public function test_product_detail_ladder_matches_the_cart(): void
    {
        PricingTier::factory()->create([
            'name' => 'Tier 1',
            'slug' => 'tier-1',
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 3000, // 30% off, in basis points
        ]);

        $product = Product::factory()->state(['retail_price_cents' => 3800])->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock_qty' => 100]);

        $user = User::factory()->create();

        $detail = $this->actingAs($user)
            ->getJson("/api/v1/products/{$product->slug}")
            ->assertOk();

        $detail->assertJsonPath('data.wholesale_locked', false);
        $detail->assertJsonPath('data.wholesale_tiers.0.unit_price.cents', 2660);
        $detail->assertJsonPath('data.wholesale_from.cents', 2660);

        $cart = $this->actingAs($user)
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => 10])
            ->assertOk();

        $this->assertSame(
            $detail->json('data.wholesale_tiers.0.unit_price.cents'),
            $cart->json('items.0.unit_price.cents'),
            'The ladder on the product page and the price in the cart must agree.'
        );
    }

    public function test_product_detail_ladder_never_reaches_a_guest(): void
    {
        PricingTier::factory()->create([
            'name' => 'Tier 1',
            'slug' => 'tier-1',
            'min_subtotal_cents' => 20000,
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 3000,
        ]);

        $product = Product::factory()->state(['retail_price_cents' => 3800])->create();
        ProductVariant::factory()->for($product)->create(['stock_qty' => 100]);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        $response->assertJsonPath('data.wholesale_locked', true);
        $response->assertJsonMissingPath('data.wholesale_from');
        $response->assertJsonMissingPath('data.wholesale_tiers');

        // 2660 cents is the wholesale figure. It must not appear anywhere in
        // the body — not as a price, not as a saving, not in any other shape.
        $this->assertStringNotContainsString('2660', $response->getContent());
        $this->assertStringNotContainsString('26.60', $response->getContent());
    }

    public function test_cart_never_charges_a_guest_the_wholesale_price(): void
    {
        $this->seedTier();
        $variant = ProductVariant::factory()->for(
            Product::factory()->state(['retail_price_cents' => 6500])
        )->create(['stock_qty' => 100]);

        // 4 x $65 = $260, comfortably past the $200 threshold.
        $response = $this->postJson('/api/v1/cart/items', [
            'variant_id' => $variant->id,
            'qty' => 4,
        ])->assertOk();

        $response->assertJsonPath('quote.subtotal_cents', 26000);
        $response->assertJsonPath('quote.wholesale_visible', false);
        $response->assertJsonPath('quote.tier', null);

        // The guest is told a saving exists and how big — but never the prices.
        $response->assertJsonPath('quote.unlock_prompt.saving_cents', 8000);
        $response->assertJsonPath('items.0.unit_price.cents', 6500);
    }

    public function test_cart_applies_wholesale_once_signed_in(): void
    {
        $this->seedTier();
        $variant = ProductVariant::factory()->for(
            Product::factory()->state(['retail_price_cents' => 6500])
        )->create(['stock_qty' => 100]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => 4])
            ->assertOk();

        $response->assertJsonPath('quote.retail_subtotal_cents', 26000);
        $response->assertJsonPath('quote.subtotal_cents', 18000);
        $response->assertJsonPath('quote.discount_cents', 8000);
        $response->assertJsonPath('quote.wholesale_visible', true);
        $response->assertJsonPath('quote.tier.name', 'Tier 1');
        $response->assertJsonPath('items.0.unit_price.cents', 4500);
    }

    public function test_cart_never_exceeds_available_stock(): void
    {
        $variant = ProductVariant::factory()->for(Product::factory())->create([
            'stock_qty' => 3,
            'reserved_qty' => 0,
        ]);

        $response = $this->postJson('/api/v1/cart/items', [
            'variant_id' => $variant->id,
            'qty' => 10,
        ])->assertOk();

        $response->assertJsonPath('items.0.qty', 3);
    }

    public function test_reserved_stock_is_not_sellable(): void
    {
        $variant = ProductVariant::factory()->for(Product::factory())->create([
            'stock_qty' => 5,
            'reserved_qty' => 4,   // held by an in-flight checkout
        ]);

        $response = $this->postJson('/api/v1/cart/items', [
            'variant_id' => $variant->id,
            'qty' => 5,
        ])->assertOk();

        $response->assertJsonPath('items.0.qty', 1);
    }
}
