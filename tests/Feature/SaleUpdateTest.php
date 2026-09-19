<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_sale_preserves_its_discount_in_the_recalculated_total(): void
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
            'discount' => 8,
            'sale_date' => now(),
        ]);

        $this->assertEquals(42, $sale->total_amount); // (10*5) - 8

        $this->putJson("/api/v1/sales/{$sale->id}", [
            'quantity' => 12,
            'unit_price' => 5,
        ])->assertOk();

        // Before the fix, editing recalculated as quantity*unit_price only
        // (60), silently dropping the existing 8 discount.
        $this->assertEquals(52, $sale->fresh()->total_amount);
    }

    public function test_a_failed_update_does_not_leave_stock_incorrectly_restored(): void
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

        // quantity: 0 fails the 'min:1' validation rule.
        $this->putJson("/api/v1/sales/{$sale->id}", [
            'quantity' => 0,
            'unit_price' => 5,
        ])->assertStatus(422);

        // Before the fix, the old stock (10) was already restored to the
        // product before validation ran, and nothing rolled it back on
        // failure — this would have been 100 instead of 90.
        $this->assertEquals(90, $product->fresh()->quantity);
    }

    public function test_updating_a_variation_sales_quantity_keeps_variation_and_product_stock_in_sync(): void
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

        $this->putJson("/api/v1/sales/{$sale->id}", [
            'quantity' => 8,
            'unit_price' => 5,
            'variation_id' => $variation->id,
        ])->assertOk();

        // Before the fix, update() only ever touched the variation OR the
        // product depending on variation_id, never both — so the
        // aggregate product quantity silently drifted from reality on any
        // edit of a variation sale.
        $this->assertEquals(22, $variation->fresh()->quantity);
        $this->assertEquals(92, $product->fresh()->quantity);
    }
}
