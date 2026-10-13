<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * DEMO_OWNER_EMAIL lets a real Zinnvy sign-in land in the populated demo workspace,
 * even when that email already belongs to a workspace on the staging database.
 */
class StagingSeederOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['SEED_DEMO_DATA', 'DEMO_OWNER_EMAIL', 'DEMO_RESEED', 'DEMO_PASSWORD'] as $k) {
            putenv($k);
        }
        parent::tearDown();
    }

    private function seed_(array $env): void
    {
        foreach ($env + ['SEED_DEMO_DATA' => 'true', 'DEMO_PASSWORD' => 'Test-Only-Pass1'] as $k => $v) {
            putenv("{$k}={$v}");
        }
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\StagingSeeder', '--force' => true]);
    }

    private function ama(): Tenant
    {
        return Tenant::where('domain', 'ama-market.demo.zinnvy.test')->firstOrFail();
    }

    public function test_it_does_nothing_without_the_flag(): void
    {
        putenv('SEED_DEMO_DATA');
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\StagingSeeder', '--force' => true]);
        $this->assertSame(0, Tenant::count());
    }

    public function test_an_existing_real_user_is_moved_into_the_demo_workspace_and_stays_linked(): void
    {
        $home = Tenant::factory()->create(['name' => 'Growth Factor Tech']);
        $me = User::factory()->create(['tenant_id' => $home->id, 'email' => 'me@example.com', 'role' => 'Administrator', 'name' => 'Real Me']);
        $me->forceFill(['zinnvy_sub' => 'zid-me'])->save();
        $oldHash = $me->password;

        $this->seed_(['DEMO_OWNER_EMAIL' => 'me@example.com']);

        $me->refresh();
        $this->assertSame($this->ama()->id, $me->tenant_id, 'now an Administrator of Ama\'s Market');
        $this->assertSame('Administrator', $me->role);
        $this->assertSame('zid-me', $me->zinnvy_sub, 'still linked to their Zinnvy account');
        $this->assertSame($oldHash, $me->password, 'password untouched');
        $this->assertSame('Real Me', $me->name);
        $this->assertTrue(Tenant::whereKey($home->id)->exists(), 'the original workspace is left in place');
        $this->assertSame(1, User::where('email', 'me@example.com')->count());
    }

    public function test_the_owner_is_attached_even_when_the_demo_tenants_already_exist(): void
    {
        $this->seed_([]);                                   // first deploy: no owner email yet
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);

        $this->seed_(['DEMO_OWNER_EMAIL' => 'late@example.com']); // later deploy adds it
        $this->assertSame($this->ama()->id, User::where('email', 'late@example.com')->value('tenant_id'));
        $this->assertSame(1, Tenant::where('name', "Ama's Market")->count(), 'no duplicate demo tenant');
    }

    public function test_a_super_admin_is_never_moved(): void
    {
        $home = Tenant::factory()->create();
        $root = User::factory()->create(['tenant_id' => $home->id, 'email' => 'root@example.com', 'role' => 'SuperAdmin']);

        $this->seed_(['DEMO_OWNER_EMAIL' => 'root@example.com']);

        $this->assertSame($home->id, $root->fresh()->tenant_id);
    }

    public function test_a_reseed_does_not_delete_the_real_owner(): void
    {
        $home = Tenant::factory()->create();
        $me = User::factory()->create(['tenant_id' => $home->id, 'email' => 'me@example.com', 'name' => 'Real Me']);
        $me->forceFill(['zinnvy_sub' => 'zid-me'])->save();

        $this->seed_(['DEMO_OWNER_EMAIL' => 'me@example.com']);
        $this->seed_(['DEMO_OWNER_EMAIL' => 'me@example.com', 'DEMO_RESEED' => 'true']); // wipes + rebuilds the demo tenants

        $again = User::where('email', 'me@example.com')->firstOrFail();
        $this->assertSame('Real Me', $again->name, 'name preserved across the purge');
        $this->assertSame('zid-me', $again->zinnvy_sub, 'Zinnvy link preserved across the purge');
        $this->assertSame($this->ama()->id, $again->tenant_id);
    }
}
