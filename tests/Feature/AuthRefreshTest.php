<?php

namespace Tests\Feature;

use App\Models\AuthRefreshToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create(['plan' => 'pro', 'is_active' => true]);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
    }

    private function me(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"]);
    }

    public function test_an_expired_access_token_is_swapped_for_a_new_pair(): void
    {
        $s = AuthSessions::issue($this->user);
        $this->me($s['token'])->assertOk();

        $this->travel(AuthSessions::ACCESS_HOURS * 60 + 1)->minutes();
        $this->me($s['token'])->assertUnauthorized();

        $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json();
        $this->assertNotSame($s['refresh_token'], $new['refresh_token'], 'rotated');
        $this->me($new['token'])->assertOk();
    }

    public function test_a_reused_refresh_token_revokes_that_devices_session(): void
    {
        $s = AuthSessions::issue($this->user);
        $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json();

        $this->travel(AuthSessions::REUSE_GRACE_SECONDS + 5)->seconds();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertUnauthorized()->assertJsonPath('code', 'refresh_reused');

        // The thief's copy killed the family: the legitimate newer pair is gone too.
        $this->me($new['token'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $new['refresh_token']])->assertUnauthorized();
    }

    public function test_two_tabs_refreshing_at_once_both_succeed(): void
    {
        $s = AuthSessions::issue($this->user);
        $a = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json();
        $b = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json();
        $this->me($a['token'])->assertOk();
        $this->me($b['token'])->assertOk();
    }

    public function test_refresh_tokens_expire_after_inactivity_and_at_the_session_cap(): void
    {
        $s = AuthSessions::issue($this->user);
        $this->travel(AuthSessions::REFRESH_DAYS + 1)->days();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertUnauthorized()->assertJsonPath('code', 'refresh_expired');

        $this->travelBack();
        $s = AuthSessions::issue($this->user);
        // Active every 20 days keeps it alive... until the overall cap.
        for ($d = 20; $d < AuthSessions::MAX_SESSION_DAYS; $d += 20) { // day 20 … 160
            $this->travel(20)->days();
            $s = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json();
        }
        $this->travel(20)->days();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertUnauthorized();
    }

    public function test_signing_in_on_another_device_keeps_this_one(): void
    {
        $laptop = AuthSessions::issue($this->user);
        AuthSessions::issue($this->user); // phone
        $this->me($laptop['token'])->assertOk();
    }

    public function test_logout_ends_only_this_device(): void
    {
        $laptop = AuthSessions::issue($this->user);
        $phone = AuthSessions::issue($this->user);

        $this->postJson('/api/v1/logout', [], ['Authorization' => "Bearer {$laptop['token']}"])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $laptop['refresh_token']])->assertUnauthorized();
        $this->me($phone['token'])->assertOk();
    }

    public function test_inactive_workspaces_cannot_refresh(): void
    {
        $s = AuthSessions::issue($this->user);
        $this->user->tenant->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $s['refresh_token']])->assertForbidden()->assertJsonPath('code', 'workspace_inactive');
    }

    public function test_zinnvy_handoff_returns_a_refresh_token(): void
    {
        $s = AuthSessions::issue($this->user);
        Cache::put('zinnvy_handoff:abc', ['user_id' => $this->user->id] + $s, 60);
        $this->postJson('/api/v1/auth/zinnvy/handoff', ['code' => 'abc'])->assertOk()
            ->assertJsonPath('refresh_token', $s['refresh_token'])->assertJsonPath('expires_in', AuthSessions::ACCESS_HOURS * 3600);
    }

    public function test_garbage_tokens_are_refused(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'nope'])->assertUnauthorized()->assertJsonPath('code', 'refresh_invalid');
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, AuthRefreshToken::count());
    }
}
