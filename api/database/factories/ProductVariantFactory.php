<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'size_id' => Size::factory(),
            'sku' => strtoupper(Str::random(12)),
            'stock_qty' => 100,
            'reserved_qty' => 0,
            'weight_grams' => 480,
            'is_active' => true,
        ];
    }
}
