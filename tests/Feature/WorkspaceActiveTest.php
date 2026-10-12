<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_workspace_locks_out_users_who_are_already_signed_in(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $tenant->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/dashboard')
            ->assertForbidden()->assertJsonPath('code', 'workspace_inactive');
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk(); // signing out still works
    }

    public function test_an_expired_subscription_alone_does_not_lock_anyone_out(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true, 'subscription_ends_at' => now()->subMonth()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->withToken($user->createToken('t')->plainTextToken)->getJson('/api/v1/me')->assertOk();
    }
}
