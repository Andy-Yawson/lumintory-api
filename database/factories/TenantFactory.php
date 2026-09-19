<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'plan' => 'pro',
            'settings' => ['currency' => 'GHS', 'currency_symbol' => 'GHS', 'low_stock_threshold' => 10],
            'is_active' => true,
        ];
    }
}
