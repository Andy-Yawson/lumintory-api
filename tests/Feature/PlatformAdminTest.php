<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private array $auth = ['Authorization' => 'Bearer s3cret'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zinnvy_platform.admin_token' => 's3cret']);
    }

    private function owner(string $sub = 'sub-1'): User
    {
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->forceFill(['zinnvy_sub' => $sub])->save();

        return $user;
    }

    public function test_requires_the_shared_secret_and_is_closed_when_unset(): void
    {
        $this->getJson('/api/v1/platform/admin/overview')->assertUnauthorized();
        $this->getJson('/api/v1/platform/admin/overview', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

        config(['services.zinnvy_platform.admin_token' => '']);
        $this->getJson('/api/v1/platform/admin/overview', ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_unknown_account_has_no_workspace(): void
    {
        $this->getJson('/api/v1/platform/admin/accounts/ghost', $this->auth)
            ->assertOk()->assertJson(['has_workspace' => false, 'stage' => 'signed_up']);
    }

    public function test_account_summary_reports_stage_shop_and_orders(): void
    {
        $user = $this->owner();
        $this->getJson('/api/v1/platform/admin/accounts/sub-1', $this->auth)->assertJsonPath('stage', 'workspace');

        $product = new Product(['name' => 'Dress', 'quantity' => 1, 'unit_price' => 10]);
        $product->tenant_id = $user->tenant_id;
        $product->shop_visible = true;
        $product->save();
        $shop = Shop::create(['tenant_id' => $user->tenant_id, 'slug' => 'closet', 'enabled' => true]);
        ShopOrder::unguarded(fn () => ShopOrder::create([
            'tenant_id' => $user->tenant_id, 'shop_id' => $shop->id, 'number' => 1001, 'status' => 'new',
            'customer_name' => 'Kofi', 'customer_phone' => '0240000000', 'fulfilment' => 'pickup',
            'subtotal' => 50, 'item_count' => 1,
        ]));

        $res = $this->getJson('/api/v1/platform/admin/accounts/sub-1', $this->auth)->assertOk();
        $res->assertJsonPath('stage', 'first_order')
            ->assertJsonPath('shop.slug', 'closet')
            ->assertJsonPath('shop.orders_total', 1)
            ->assertJsonPath('shop.listed_products', 1)
            ->assertJsonPath('inventory.products', 1);
        $this->assertStringNotContainsString('Kofi', $res->getContent(), 'no customer details leave Inventory');
    }

    public function test_lifecycle_and_overview(): void
    {
        $this->owner('sub-1');
        $this->postJson('/api/v1/platform/admin/lifecycle', ['subs' => ['sub-1', 'ghost']], $this->auth)
            ->assertOk()->assertJsonPath('stages.sub-1', 'workspace')->assertJsonPath('stages.ghost', 'signed_up');

        $this->getJson('/api/v1/platform/admin/overview', $this->auth)
            ->assertOk()->assertJsonPath('workspaces.total', 1)->assertJsonPath('funnel.with_product', 0);
    }
}
