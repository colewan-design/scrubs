<?php

namespace Database\Factories;

use App\Models\PricingTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PricingTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Tier '.fake()->unique()->numberBetween(1, 999),
            'slug' => 'tier-'.Str::random(6),
            'min_subtotal_cents' => 20000,      // the brief's $200 opening MOQ
            'min_qty' => null,
            'qualify_mode' => 'any',
            'discount_type' => PricingTier::DISCOUNT_PERCENT,
            'discount_value' => 1000,           // basis points -> 10%
            'priority' => 1,
            'is_active' => true,
        ];
    }
}
