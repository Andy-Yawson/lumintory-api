<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IntegrationApiKey;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CopilotContextToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zinnvy AI Co-Pilot: the signed page context, and the membership check Zinnvy AI relies on. */
class CopilotContextTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $role = 'Administrator'): array
    {
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'zinnvy_sub' => (string) Str::uuid()]);
        $key = IntegrationApiKey::create(['tenant_id' => $tenant->id, 'name' => 'Zinnvy AI Co-Pilot', 'public_key' => 'int_'.Str::random(40), 'secret' => Str::random(60), 'scopes' => ['ai:read']]);

        return [$tenant, $user, $key];
    }

    private function decode(string $token, IntegrationApiKey $key): array
    {
        [$head, $body, $sig] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$head}.{$body}", CopilotContextToken::signingSecret($key), true)), '+/', '-_'), '=');
        $this->assertTrue(hash_equals($expected, $sig), 'the signature verifies with the derived secret');

        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    }

    public function test_connecting_hands_zinnvy_ai_a_derived_secret_not_the_raw_one(): void
    {
        [, $user, $key] = $this->shop();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/integrations/ai/provision')->assertOk();

        $this->assertSame(CopilotContextToken::signingSecret($key), $res->json('signing_secret'));
        $this->assertNotSame($key->secret, $res->json('signing_secret'));
    }

    public function test_a_signed_context_names_the_user_shop_and_page_and_expires_soon(): void
    {
        [$tenant, $user, $key] = $this->shop('Sales');
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Paracetamol 500mg']);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/copilot/context', ['page' => 'product', 'entity_id' => $product->id])->assertOk();
        $claims = $this->decode($res->json('token'), $key);

        $this->assertSame((string) $tenant->id, $claims['tenant']);
        $this->assertSame($user->zinnvy_sub, $claims['sub']);
        $this->assertSame('Sales', $claims['role']);
        $this->assertSame(['key' => 'product', 'entity' => ['type' => 'product', 'id' => $product->id, 'name' => 'Paracetamol 500mg']], $claims['page']);
        $this->assertSame('zinnvy-ai', $claims['aud']);
        $this->assertLessThanOrEqual(900, $claims['exp'] - $claims['iat']);
    }

    public function test_an_entity_from_another_shop_is_not_found_so_its_name_never_leaks(): void
    {
        [, $user, $key] = $this->shop();
        [$otherTenant] = $this->shop();
        $theirs = Product::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'SECRET-OTHER-SHOP-PRODUCT']);
        $theirCustomer = Customer::create(['tenant_id' => $otherTenant->id, 'name' => 'SECRET-OTHER-CUSTOMER']);
        Sanctum::actingAs($user);

        foreach ([['product', $theirs->id], ['customer', $theirCustomer->id]] as [$page, $id]) {
            $res = $this->postJson('/api/v1/copilot/context', ['page' => $page, 'entity_id' => $id])->assertOk();
            $this->assertNull($this->decode($res->json('token'), $key)['page']['entity'], 'no entity is named');
            $this->assertStringNotContainsString('SECRET-OTHER', json_encode($this->decode($res->json('token'), $key)));
        }
    }

    public function test_the_context_needs_a_signed_in_user_a_known_page_and_a_connected_shop(): void
    {
        [$tenant, $user] = $this->shop();
        $this->postJson('/api/v1/copilot/context', ['page' => 'products'])->assertUnauthorized();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/copilot/context', ['page' => 'admin-secrets'])->assertUnprocessable();
        IntegrationApiKey::where('tenant_id', $tenant->id)->update(['is_active' => false]);
        $this->postJson('/api/v1/copilot/context', ['page' => 'products'])->assertStatus(409);
    }

    public function test_a_token_is_signed_per_shop_so_one_shops_secret_cannot_vouch_for_another(): void
    {
        [, $userA, $keyA] = $this->shop();
        [, , $keyB] = $this->shop();
        Sanctum::actingAs($userA);
        $token = $this->postJson('/api/v1/copilot/context', ['page' => 'products'])->json('token');

        [$head, $body, $sig] = explode('.', $token);
        $wrong = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$head}.{$body}", CopilotContextToken::signingSecret($keyB), true)), '+/', '-_'), '=');
        $this->assertFalse(hash_equals($wrong, $sig));
        $this->assertNotSame(CopilotContextToken::signingSecret($keyA), CopilotContextToken::signingSecret($keyB));
    }

    public function test_the_membership_check_answers_only_for_the_keys_own_shop(): void
    {
        [, $member, $key] = $this->shop('Sales');
        [, $stranger] = $this->shop();
        $call = fn (string $sub) => $this->getJson("/api/v1/integrations/ai/members/{$sub}", ['Authorization' => 'Bearer '.$key->public_key]);

        $call($member->zinnvy_sub)->assertOk()->assertJson(['member' => true, 'role' => 'Sales']);
        $call($stranger->zinnvy_sub)->assertNotFound()->assertJson(['member' => false]);
        $call('no-such-person')->assertNotFound();
        $this->getJson("/api/v1/integrations/ai/members/{$member->zinnvy_sub}")->assertUnauthorized();
        IntegrationApiKey::whereKey($key->id)->update(['scopes' => ['products:read']]);
        $call($member->zinnvy_sub)->assertForbidden();
    }
}
