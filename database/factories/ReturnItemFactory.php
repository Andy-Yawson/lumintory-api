<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReturnItem>
 */
class ReturnItemFactory extends Factory
{
    public function definition(): array
    {
        $sale = Sale::factory()->create();

        return [
            'tenant_id' => $sale->tenant_id,
            'sale_id' => $sale->id,
            'product_id' => $sale->product_id,
            'quantity' => 1,
            'refund_amount' => $sale->unit_price,
            'reason' => 'Customer changed mind',
            'return_date' => now(),
            'refund_method' => 'cash',
        ];
    }
}
