<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_counts_the_inspected_workspace_not_the_admins_own(): void
    {
        $platform = Tenant::factory()->create(['plan' => 'custom']);
        $super = User::factory()->create(['tenant_id' => $platform->id, 'role' => 'SuperAdmin']);

        $shop = Tenant::factory()->create(['plan' => 'basic']);
        Product::withoutGlobalScopes()->insert(array_map(fn ($i) => [
            'tenant_id' => $shop->id, 'name' => "P{$i}", 'unit_price' => 5, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], range(1, 3)));
        Customer::withoutGlobalScopes()->insert([['tenant_id' => $shop->id, 'name' => 'C1', 'created_at' => now(), 'updated_at' => now()]]);

        Sanctum::actingAs($super);

        $this->getJson("/api/v1/admin/tenants/{$shop->id}/usage")
            ->assertOk()
            ->assertJsonPath('products.used', 3)
            ->assertJsonPath('products.limit', 10)
            ->assertJsonPath('customers.used', 1);
    }
}
