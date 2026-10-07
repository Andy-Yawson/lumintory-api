<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\Tenant;
use App\Models\TenantToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every registration starts at Zinnvy Identity. Inventory only creates the workspace for a
 * verified Zinnvy account, linked to it, and never creates credentials of its own.
 */
class ZinnvySignupTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIdentity(array $claims): void
    {
        config(['zinnvy.frontend_callback_url' => 'https://app.test/oauth/callback', 'zinnvy.accounts_url' => 'https://accounts.test']);
        Http::fake([
            'accounts.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'accounts.test/oauth/userinfo' => Http::response($claims),
        ]);
    }

    /** Runs the OAuth callback and returns the sign-up code from the redirect. */
    private function arriveFromIdentity(array $claims, ?string $ref = null): string
    {
        $this->fakeIdentity($claims);
        Cache::put('zinnvy_oauth_state:s1', ['ref' => $ref], 60);

        $location = $this->get('/api/v1/auth/zinnvy/callback?state=s1&code=c')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://app.test/oauth/callback?signup=', $location);

        return substr($location, strlen('https://app.test/oauth/callback?signup='));
    }

    public function test_a_verified_zinnvy_account_with_no_workspace_is_sent_to_sign_up_not_an_error(): void
    {
        $code = $this->arriveFromIdentity(['sub' => 'z-1', 'email' => 'ama@example.com', 'email_verified' => true, 'name' => 'Ama Mensah']);

        $this->getJson("/api/v1/auth/zinnvy/signup/{$code}")
            ->assertOk()->assertJson(['name' => 'Ama Mensah', 'email' => 'ama@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'ama@example.com']); // nothing created yet
    }

    public function test_completing_sign_up_creates_the_workspace_linked_to_the_zinnvy_account(): void
    {
        $code = $this->arriveFromIdentity(['sub' => 'z-1', 'email' => 'ama@example.com', 'email_verified' => true, 'name' => 'Ama Mensah']);

        $res = $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => "Ama's Market", 'currency' => 'GHS', 'currency_symbol' => 'GH₵'])
            ->assertCreated()->assertJsonPath('user.role', 'Administrator')->assertJsonPath('tenant.name', "Ama's Market");

        $user = User::where('email', 'ama@example.com')->firstOrFail();
        $this->assertSame('z-1', $user->zinnvy_sub, 'linked to the Zinnvy account');
        $this->assertSame('Ama Mensah', $user->name, 'name comes from Zinnvy, not a form');
        $this->assertSame('GH₵', $user->tenant->settings['currency_symbol']);
        $this->assertDatabaseHas('sms_credits', ['tenant_id' => $user->tenant_id]);
        $this->assertDatabaseHas('subscription_histories', ['tenant_id' => $user->tenant_id, 'event_type' => 'signup']);
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_the_new_owner_has_no_usable_password(): void
    {
        $code = $this->arriveFromIdentity(['sub' => 'z-1', 'email' => 'ama@example.com', 'email_verified' => true, 'name' => 'Ama']);
        $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => 'Shop'])->assertCreated();

        foreach (['password', 'Password123!'] as $guess) {
            $this->postJson('/api/v1/login', ['email' => 'ama@example.com', 'password' => $guess])->assertStatus(404);
        }
    }

    public function test_a_sign_up_code_works_once(): void
    {
        $code = $this->arriveFromIdentity(['sub' => 'z-1', 'email' => 'ama@example.com', 'email_verified' => true, 'name' => 'Ama']);

        $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => 'Shop'])->assertCreated();
        $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => 'Shop again'])->assertNotFound();
        $this->assertSame(1, Tenant::count());
    }

    public function test_a_referral_code_from_the_register_link_credits_the_referrer(): void
    {
        $referrer = Tenant::factory()->create(['plan' => 'pro', 'referral_code' => 'REF12345']);
        TenantToken::create(['tenant_id' => $referrer->id, 'balance' => 0]);

        $code = $this->arriveFromIdentity(['sub' => 'z-2', 'email' => 'new@example.com', 'email_verified' => true, 'name' => 'New'], 'REF12345');
        $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => 'Referred Shop'])->assertCreated();

        $this->assertDatabaseHas('referrals', ['referrer_tenant_id' => $referrer->id, 'tokens_awarded' => 10]);
        $this->assertSame(10, (int) TenantToken::where('tenant_id', $referrer->id)->value('balance'));
        $this->assertSame(1, Referral::count());
    }

    public function test_an_account_that_already_exists_is_not_duplicated(): void
    {
        $code = $this->arriveFromIdentity(['sub' => 'z-3', 'email' => 'late@example.com', 'email_verified' => true, 'name' => 'Late']);
        // An admin invites the same email between the OAuth step and the form submit.
        User::factory()->create(['email' => 'late@example.com', 'tenant_id' => Tenant::factory()->create()->id]);

        $this->postJson('/api/v1/auth/zinnvy/signup', ['code' => $code, 'tenant_name' => 'Shop'])->assertStatus(409);
        $this->assertSame(1, User::where('email', 'late@example.com')->count());
    }

    public function test_an_unverified_zinnvy_email_never_gets_a_workspace(): void
    {
        $this->fakeIdentity(['sub' => 'z-4', 'email' => 'x@example.com', 'email_verified' => false, 'name' => 'X']);
        Cache::put('zinnvy_oauth_state:s1', ['ref' => null], 60);

        // An unverified identity can't claim an email, so it never reaches the sign-up step.
        $loc = $this->get('/api/v1/auth/zinnvy/callback?state=s1&code=c')->headers->get('Location');
        $this->assertStringContainsString('error=email_unverified', $loc);
        $this->assertStringNotContainsString('signup=', $loc);
    }

    public function test_password_registration_is_retired(): void
    {
        $this->postJson('/api/v1/register-tenant', ['tenant_name' => 'X', 'user_name' => 'Y', 'email' => 'y@example.com', 'password' => 'secret12'])
            ->assertStatus(410);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
