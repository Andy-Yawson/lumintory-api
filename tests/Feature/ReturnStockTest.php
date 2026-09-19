<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_a_return_restores_stock_exactly_once(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $this->actingAs($admin, 'sanctum');

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'quantity' => 100]);
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 5,
            'sale_date' => now(),
        ]);

        $this->assertEquals(90, $product->fresh()->quantity);

        $response = $this->postJson('/api/v1/returns', [
            'returns' => [[
                'sale_id' => $sale->id,
                'quantity' => 4,
                'reason' => 'Damaged',
                'return_date' => now()->toDateString(),
                'refund_method' => 'cash',
            ]],
        ]);

        $response->assertStatus(201);
        // Before the fix, this would be 98 (90 + 4 + 4) — restored twice.
        $this->assertEquals(94, $product->fresh()->quantity);
    }

    public function test_processing_a_return_for_a_variation_restores_both_variation_and_product_quantity(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $this->actingAs($admin, 'sanctum');

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'quantity' => 100]);
        $variation = ProductVariation::factory()->create(['product_id' => $product->id, 'tenant_id' => $tenant->id, 'quantity' => 30]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'quantity' => 5,
            'unit_price' => 5,
            'sale_date' => now(),
        ]);

        $this->assertEquals(25, $variation->fresh()->quantity);
        $this->assertEquals(95, $product->fresh()->quantity);

        $this->postJson('/api/v1/returns', [
            'returns' => [[
                'sale_id' => $sale->id,
                'quantity' => 2,
                'reason' => 'Wrong size',
                'return_date' => now()->toDateString(),
                'refund_method' => 'cash',
            ]],
        ])->assertStatus(201);

        // Both the variation and the aggregate product quantity should move
        // together, exactly once each — this was the bug: only the base
        // product got restored (and even that, twice).
        $this->assertEquals(27, $variation->fresh()->quantity);
        $this->assertEquals(97, $product->fresh()->quantity);
    }

    public function test_deleting_a_return_reverses_stock_exactly_once(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $this->actingAs($admin, 'sanctum');

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'quantity' => 100]);
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 5,
            'sale_date' => now(),
        ]);

        $returnItem = ReturnItem::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'refund_amount' => 20,
            'reason' => 'Damaged',
            'return_date' => now(),
            'refund_method' => 'cash',
        ]);

        $this->assertEquals(94, $product->fresh()->quantity);

        $this->deleteJson("/api/v1/returns/{$returnItem->id}")->assertStatus(204);

        // Before the fix, this would be 86 (94 - 4 - 4) — decremented twice.
        $this->assertEquals(90, $product->fresh()->quantity);
    }
}
