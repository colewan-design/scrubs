<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SizeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'position' => 0,
            'is_active' => true,
        ];
    }
}
