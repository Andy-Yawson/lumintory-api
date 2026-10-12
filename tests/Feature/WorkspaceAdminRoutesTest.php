<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Integrations, API keys, the audit trail and AI access are workspace-admin only. */
class WorkspaceAdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    public static function adminOnly(): array
    {
        return [
            'create api key' => ['POST', '/api/v1/integration-keys', ['name' => 'k']],
            'list api keys' => ['GET', '/api/v1/integration-keys', []],
            'list stores' => ['GET', '/api/v1/stores', []],
            'connect woo' => ['POST', '/api/v1/stores/woocommerce/connect', ['store_url' => 'https://x.example']],
            'audit logs' => ['GET', '/api/v1/audit/logs', []],
            'ai provision' => ['POST', '/api/v1/integrations/ai/provision', []],
            'workspace settings' => ['POST', '/api/v1/tenant/settings', ['currency' => 'USD']],
        ];
    }

    /** @dataProvider adminOnly */
    public function test_a_sales_user_is_refused(string $method, string $uri, array $body): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Sales']));

        $this->json($method, $uri, $body)->assertForbidden();
    }

    public function test_an_administrator_is_allowed(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']));

        $this->postJson('/api/v1/integration-keys', ['name' => 'k'])->assertCreated();
        $this->getJson('/api/v1/audit/logs')->assertOk();
        $this->postJson('/api/v1/tenant/settings', ['currency' => 'NGN'])->assertOk()->assertJsonPath('data.currency_symbol', '₦');
    }
}
