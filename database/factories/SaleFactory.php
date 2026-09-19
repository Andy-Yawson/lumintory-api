<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 *
 * Sale's own creating() hook decrements real stock and throws on
 * insufficient quantity, so tests must actingAs() a user before calling
 * this, and the product must already have enough quantity.
 */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        $product = Product::factory()->create();

        return [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->unit_price,
            'sale_date' => now(),
            'payment_method' => 'cash',
        ];
    }
}
