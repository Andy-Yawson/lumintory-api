<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(3, true),
            'quantity' => 100,
            'unit_price' => fake()->randomFloat(2, 5, 200),
            'lead_time_days' => 7,
            'min_stock_threshold' => 10,
        ];
    }
}
