<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function step(array $json, string $key): bool
    {
        return collect($json['steps'])->firstWhere('key', $key)['done'];
    }

    public function test_a_new_workspace_has_nothing_done(): void
    {
        $this->admin();

        $json = $this->getJson('/api/v1/onboarding')->assertOk()->json();

        $this->assertTrue($json['visible']);
        $this->assertFalse($json['dismissed']);
        $this->assertFalse(collect($json['steps'])->contains('done', true));
    }

    public function test_steps_tick_off_from_real_data(): void
    {
        $this->admin();
        $id = $this->postJson('/api/v1/products', ['name' => 'Soap', 'unit_price' => 5, 'quantity' => 10, 'min_stock_threshold' => 3])->json('id');
        $this->assertNotNull($id);

        $json = $this->getJson('/api/v1/onboarding')->json();

        $this->assertTrue($this->step($json, 'products'));
        $this->assertTrue($this->step($json, 'restock'));
        $this->assertFalse($this->step($json, 'sale'));
        $this->assertFalse($this->step($json, 'team'));
    }

    public function test_another_workspaces_data_does_not_count(): void
    {
        $other = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $other->id]);
        $this->admin();

        $this->assertFalse($this->step($this->getJson('/api/v1/onboarding')->json(), 'team'));
    }

    public function test_an_admin_can_dismiss_it_and_a_sales_user_cannot(): void
    {
        $admin = $this->admin();
        $sales = User::factory()->create(['tenant_id' => $admin->tenant_id, 'role' => 'Sales']);

        Sanctum::actingAs($sales);
        $this->assertFalse($this->getJson('/api/v1/onboarding')->json('visible'));
        $this->postJson('/api/v1/onboarding/dismiss')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/onboarding/dismiss')->assertOk();
        $this->assertTrue($this->getJson('/api/v1/onboarding')->json('dismissed'));
    }
}
