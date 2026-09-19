<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_subscription_only_ever_affects_the_callers_own_tenant(): void
    {
        $ownTenant = Tenant::factory()->create(['plan' => 'basic']);
        $otherTenant = Tenant::factory()->create(['plan' => 'basic']);
        $user = User::factory()->create(['tenant_id' => $ownTenant->id]);

        // Before the fix, tenant_id came straight from the request body —
        // a user from any tenant could activate/reset a different
        // tenant's subscription just by passing its id.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/activate-subscription', [
                'tenant_id' => $otherTenant->id,
                'plan' => 'yearly',
            ])->assertOk();

        $this->assertSame('basic', $otherTenant->fresh()->plan);
        $this->assertSame('pro', $ownTenant->fresh()->plan);
    }

    public function test_activating_a_yearly_subscription_sets_the_pro_tier_without_a_database_error(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'basic']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Before the fix, this always 500'd — tenants.plan is a DB
        // enum('basic','pro','custom') and the endpoint tried to write the
        // literal string 'yearly' into it.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/activate-subscription', ['plan' => 'yearly'])
            ->assertOk();

        $this->assertSame('pro', $tenant->fresh()->plan);
        $this->assertTrue(Carbon::parse($tenant->fresh()->subscription_ends_at)->isAfter(now()->addMonths(11)));
    }

    public function test_setting_the_subscription_to_free_maps_to_the_basic_tier_without_a_database_error(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/subscription', ['plan' => 'free'])
            ->assertOk();

        $this->assertSame('basic', $tenant->fresh()->plan);
    }
}
