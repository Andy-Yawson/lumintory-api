<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The product form sends what the UI holds; these pin down how create/update interpret it. */
class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_simple_product_can_be_created_with_an_empty_variation_list(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/products', ['name' => 'Notebook', 'unit_price' => 15, 'quantity' => 40, 'variations' => []])
            ->assertCreated();

        $p = Product::withoutGlobalScopes()->where('name', 'Notebook')->firstOrFail();
        $this->assertEquals(40, $p->quantity);
        $this->assertSame(0, $p->variations()->count());
    }

    public function test_editing_a_simple_product_keeps_its_stock_when_quantity_and_an_empty_list_are_sent(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/v1/products', ['name' => 'Soap', 'unit_price' => 5, 'quantity' => 12])->json('id');

        $this->putJson("/api/v1/products/{$id}", ['name' => 'Soap', 'unit_price' => 6, 'quantity' => 12])->assertOk();

        $p = Product::withoutGlobalScopes()->find($id);
        $this->assertEquals(6, $p->unit_price);
        $this->assertEquals(12, $p->quantity, 'stock untouched');
    }

    public function test_adding_variations_on_edit_makes_stock_their_sum_even_if_quantity_is_sent(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/v1/products', ['name' => 'Shirt', 'unit_price' => 50, 'quantity' => 3])->json('id');

        $this->putJson("/api/v1/products/{$id}", [
            'name' => 'Shirt', 'unit_price' => 50, 'quantity' => 999,
            'variations' => [['name' => 'S', 'quantity' => 4, 'unit_price' => 50], ['name' => 'M', 'quantity' => 0, 'unit_price' => 50]],
        ])->assertOk();

        $p = Product::withoutGlobalScopes()->find($id);
        $this->assertEquals(4, $p->quantity);
        $this->assertSame(2, $p->variations()->withoutGlobalScopes()->count(), 'a 0-stock variation is allowed');
    }

    public function test_an_empty_list_on_edit_turns_a_variation_product_back_into_a_simple_one(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/v1/products', [
            'name' => 'Cap', 'unit_price' => 10, 'variations' => [['name' => 'Red', 'quantity' => 2]],
        ])->json('id');

        $this->putJson("/api/v1/products/{$id}", ['name' => 'Cap', 'unit_price' => 10, 'quantity' => 9, 'variations' => []])->assertOk();

        $p = Product::withoutGlobalScopes()->find($id);
        $this->assertEquals(9, $p->quantity);
        $this->assertSame(0, ProductVariation::withoutGlobalScopes()->where('product_id', $id)->count());
    }

    public function test_a_product_cannot_edit_another_products_variation(): void
    {
        $this->actAsAdmin();
        $a = $this->postJson('/api/v1/products', ['name' => 'A', 'unit_price' => 1, 'variations' => [['name' => 'A1', 'quantity' => 5]]])->json();
        $b = $this->postJson('/api/v1/products', ['name' => 'B', 'unit_price' => 1, 'quantity' => 1])->json('id');
        $foreignId = $a['variations'][0]['id'];

        $this->putJson("/api/v1/products/{$b}", ['name' => 'B', 'unit_price' => 1, 'variations' => [['id' => $foreignId, 'name' => 'hijacked', 'quantity' => 0]]])
            ->assertOk();

        $this->assertSame('A1', ProductVariation::withoutGlobalScopes()->find($foreignId)->name);
    }
}
