<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']));
    }

    private function product(string $name, float $qty, ?Tenant $t = null): Product
    {
        $p = new Product(['name' => $name, 'unit_price' => 10, 'quantity' => $qty]);
        $p->tenant_id = ($t ?? $this->tenant)->id;
        $p->saveQuietly();

        return $p;
    }

    private function sell(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', $extra + ['payment_method' => 'Cash', 'items' => $items]);
    }

    public function test_overselling_is_a_422_that_names_the_product_and_records_nothing(): void
    {
        $soap = $this->product('Shea Soap', 2);
        $tea = $this->product('Tea', 50);

        $this->sell([
            ['product_id' => $tea->id, 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => $soap->id, 'quantity' => 5, 'unit_price' => 10],
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Shea Soap'));

        $this->assertDatabaseCount('sales', 0);
        $this->assertEquals(50, Product::withoutGlobalScopes()->find($tea->id)->quantity, 'whole sale rolled back');
    }

    public function test_another_workspaces_product_or_customer_is_rejected(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->product('Theirs', 10, $other);
        $theirCustomer = Customer::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Not yours']);
        $mine = $this->product('Mine', 10);

        $this->sell([['product_id' => $theirs->id, 'quantity' => 1, 'unit_price' => 10]])->assertStatus(422);
        $this->sell([['product_id' => $mine->id, 'quantity' => 1, 'unit_price' => 10]], ['customer_id' => $theirCustomer->id])->assertStatus(422);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_variation_must_belong_to_the_product(): void
    {
        $shirt = $this->product('Shirt', 5);
        $cap = $this->product('Cap', 5);
        $capRed = ProductVariation::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'product_id' => $cap->id, 'name' => 'Red', 'quantity' => 5]);

        $this->sell([['product_id' => $shirt->id, 'variation_id' => $capRed->id, 'quantity' => 1, 'unit_price' => 10]])->assertStatus(422);
        $this->assertEquals(5, ProductVariation::withoutGlobalScopes()->find($capRed->id)->quantity);
    }

    public function test_quantity_must_be_positive(): void
    {
        $p = $this->product('Tea', 5);
        $this->sell([['product_id' => $p->id, 'quantity' => 0, 'unit_price' => 10]])->assertStatus(422);
        $this->sell([['product_id' => $p->id, 'quantity' => -3, 'unit_price' => 10]])->assertStatus(422);
        $this->assertEquals(5, Product::withoutGlobalScopes()->find($p->id)->quantity, 'a negative sale must not add stock');
    }

    public function test_the_receipt_renders_with_variation_and_discount(): void
    {
        $shirt = $this->product('Shirt', 5);
        $m = ProductVariation::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'product_id' => $shirt->id, 'name' => 'Size M', 'quantity' => 5]);
        $id = $this->sell([['product_id' => $shirt->id, 'variation_id' => $m->id, 'quantity' => 1, 'unit_price' => 100]], ['overall_discount' => 10])
            ->assertCreated()->json('sales.0.id');

        $this->get("/api/v1/sales/{$id}/receipt")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
