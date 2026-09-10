<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name' => ucwords($name),
            'slug' => Str::slug($name).'-'.Str::random(4),
            'base_sku' => strtoupper(Str::random(8)),
            'product_type' => 'set',
            'has_dual_sizing' => false,
            // The brief's reference retail price: ~CAD $65 per scrub set.
            'retail_price_cents' => 6500,
            'is_active' => true,
            'published_at' => now(),
        ];
    }
}
