<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Team members sign in with "Continue with Zinnvy": the admin invites by email,
 * and the OAuth callback links the Zinnvy account to the invited user by verified
 * email. These tests pin down the invite lifecycle and the rules around it.
 */
class TeamInviteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(?Tenant $tenant = null, string $role = 'Administrator'): User
    {
        $tenant ??= Tenant::factory()->create(['plan' => 'custom']); // custom = no user cap

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    public function test_an_administrator_invites_a_teammate_without_a_password(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/add-user', ['name' => 'Kofi', 'email' => 'kofi@example.com', 'role' => 'Sales'])
            ->assertCreated();

        $kofi = User::where('email', 'kofi@example.com')->firstOrFail();
        $this->assertSame($admin->tenant_id, $kofi->tenant_id);
        $this->assertTrue((bool) $kofi->first_login, 'invited people start as "Invited"');
        $this->assertNull($kofi->zinnvy_sub);
        $this->assertNotEmpty($kofi->password, 'a random unusable password keeps the row valid');
    }

    public function test_only_an_administrator_can_manage_the_team(): void
    {
        $sales = $this->admin(role: 'Sales');
        Sanctum::actingAs($sales);

        $this->postJson('/api/v1/add-user', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'Administrator'])->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$sales->id)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_the_invited_person_signs_in_with_zinnvy_and_is_linked_by_verified_email(): void
    {
        $admin = $this->admin();
        $invited = User::factory()->create(['tenant_id' => $admin->tenant_id, 'email' => 'new@example.com', 'role' => 'Sales', 'first_login' => true]);

        config([
            'zinnvy.frontend_callback_url' => 'https://app.test/oauth/callback',
            'zinnvy.accounts_url' => 'https://accounts.test',
        ]);
        Cache::put('zinnvy_oauth_state:abc', true, 60);
        Http::fake([
            'accounts.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'accounts.test/oauth/userinfo' => Http::response(['sub' => 'zid-1', 'email' => 'new@example.com', 'email_verified' => true]),
        ]);

        $this->get('/api/v1/auth/zinnvy/callback?state=abc&code=c')
            ->assertRedirectContains('https://app.test/oauth/callback?handoff=');

        $invited->refresh();
        $this->assertSame('zid-1', $invited->zinnvy_sub);
        $this->assertFalse((bool) $invited->first_login, 'first sign-in clears the Invited state');
    }

    public function test_an_unverified_zinnvy_email_gets_a_specific_error_not_no_account(): void
    {
        $admin = $this->admin();
        User::factory()->create(['tenant_id' => $admin->tenant_id, 'email' => 'new@example.com', 'first_login' => true]);

        config(['zinnvy.frontend_callback_url' => 'https://app.test/oauth/callback', 'zinnvy.accounts_url' => 'https://accounts.test']);
        Cache::put('zinnvy_oauth_state:abc', true, 60);
        Http::fake([
            'accounts.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'accounts.test/oauth/userinfo' => Http::response(['sub' => 'zid-2', 'email' => 'new@example.com', 'email_verified' => false]),
        ]);

        $this->get('/api/v1/auth/zinnvy/callback?state=abc&code=c')->assertRedirectContains('error=email_unverified');
        $this->assertNull(User::where('email', 'new@example.com')->value('zinnvy_sub'));
    }

    public function test_resend_only_works_for_people_who_have_not_signed_in_yet(): void
    {
        $admin = $this->admin();
        $pending = User::factory()->create(['tenant_id' => $admin->tenant_id, 'first_login' => true]);
        $active = User::factory()->create(['tenant_id' => $admin->tenant_id, 'first_login' => false]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/users/{$pending->id}/resend-invite")->assertOk();
        $this->postJson("/api/v1/users/{$active->id}/resend-invite")->assertStatus(422);
    }

    public function test_removing_access_has_guard_rails(): void
    {
        $admin = $this->admin();
        $sales = User::factory()->create(['tenant_id' => $admin->tenant_id, 'role' => 'Sales']);
        $stranger = $this->admin(); // another workspace
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/users/'.$admin->id)->assertStatus(422);           // not yourself
        $this->deleteJson('/api/v1/users/'.$stranger->id)->assertNotFound();           // not another workspace
        $this->deleteJson('/api/v1/users/'.$sales->id)->assertOk();                    // a teammate is fine
        $this->assertDatabaseMissing('users', ['id' => $sales->id]);
    }

    public function test_another_administrator_can_be_removed_while_you_remain(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        $one = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $two = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);

        Sanctum::actingAs($one);
        $this->deleteJson('/api/v1/users/'.$two->id)->assertOk(); // you remain, so the workspace still has an Administrator
        $this->assertDatabaseMissing('users', ['id' => $two->id]);
    }

    public function test_workspaces_cannot_grant_themselves_a_paid_plan(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'basic', 'is_active' => true]);
        $admin = $this->admin($tenant);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/activate-subscription', ['plan' => 'yearly'])->assertForbidden();
        $this->postJson('/api/v1/subscription', ['plan' => 'monthly'])->assertForbidden();
        $this->assertSame('basic', $tenant->fresh()->plan);
    }

    public function test_the_import_dry_run_reports_without_writing_and_matches_the_real_import(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs($this->admin($tenant));

        $csv = "Item,Code,Qty,Price\nJam,J-1,5,10\nSoap,J-1,2,3\n,X,1,1\nTea,T-1,4,6\n";
        $file = fn () => UploadedFile::fake()->createWithContent('p.csv', $csv);
        $mapping = json_encode(['name' => 0, 'sku' => 1, 'quantity' => 2, 'unit_price' => 3]);

        $plan = $this->post('/api/v1/products/import/dry-run', ['file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->json('summary');
        $this->assertSame(['create' => 2, 'update' => 0, 'skip' => 1, 'error' => 1], $plan);
        $this->assertDatabaseCount('products', 0);

        $real = $this->post('/api/v1/products/import', ['file' => $file(), 'mapping' => $mapping, 'duplicates' => 'update'], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame([2, 0, 1, 1], [$real['created'], $real['updated'], $real['skipped'], $real['failed']]);
        $this->assertSame('Jam', \App\Models\Product::withoutGlobalScopes()->where('sku', 'J-1')->value('name'), 'first row wins on a duplicate SKU');
    }
}
