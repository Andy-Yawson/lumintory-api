<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StoreConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shpss_testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.client_id' => 'client123',
            'services.shopify.client_secret' => self::SECRET,
            'services.frontend_url' => 'https://app.test',
        ]);
    }

    private function proAdmin(): User
    {
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function activeShopify(Tenant|int $tenant): StoreConnection
    {
        return StoreConnection::create([
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
            'provider' => 'shopify', 'store_url' => 'demo.myshopify.com',
            'status' => 'active', 'credentials' => ['access_token' => 'tok'],
        ]);
    }

    private function shopifyHmac(array $q): array
    {
        ksort($q);
        $msg = collect($q)->map(fn ($v, $k) => "$k=$v")->implode('&');
        $q['hmac'] = hash_hmac('sha256', $msg, self::SECRET);

        return $q;
    }

    private function postWebhook(string $topic, array $payload, ?string $sig = null)
    {
        $body = json_encode($payload);
        $sig ??= base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        return $this->call('POST', '/api/v1/webhooks/shopify', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'demo.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    // ------------------------------------------------------------- Shopify OAuth

    public function test_shop_names_are_normalised_and_foreign_hosts_rejected(): void
    {
        $this->assertSame('my-shop.myshopify.com', \App\Services\Integrations\ShopifyService::normaliseShop('https://My-Shop.myshopify.com/admin'));
        $this->assertSame('my-shop.myshopify.com', \App\Services\Integrations\ShopifyService::normaliseShop('my-shop'));
        $this->assertNull(\App\Services\Integrations\ShopifyService::normaliseShop('evil.com'));
    }

    public function test_connecting_returns_a_shopify_authorize_url_with_state(): void
    {
        $this->proAdmin();

        $url = $this->postJson('/api/v1/stores/shopify/connect', ['shop' => 'demo'])
            ->assertOk()->json('url');

        $this->assertStringStartsWith('https://demo.myshopify.com/admin/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=client123', $url);
        $this->assertNotNull(StoreConnection::first()->state_token);
    }

    public function test_connect_needs_the_plan_feature(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'basic']);
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']), 'sanctum');

        $this->postJson('/api/v1/stores/shopify/connect', ['shop' => 'demo'])->assertStatus(403);
    }

    public function test_the_oauth_callback_rejects_a_bad_signature(): void
    {
        $this->proAdmin();
        $this->postJson('/api/v1/stores/shopify/connect', ['shop' => 'demo']);
        $state = StoreConnection::first()->state_token;

        $this->get('/api/v1/stores/shopify/callback?' . http_build_query([
            'shop' => 'demo.myshopify.com', 'code' => 'abc', 'state' => $state, 'hmac' => 'forged',
        ]))->assertRedirect('https://app.test/dashboard/settings/integrations?shopify=invalid');

        $this->assertSame('pending', StoreConnection::first()->status);
    }

    public function test_the_oauth_callback_stores_the_token_and_pulls_products(): void
    {
        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_secret']),
            'demo.myshopify.com/admin/api/*/webhooks.json' => Http::response([], 201),
            'demo.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => [[
                'id' => 111, 'title' => 'Mug', 'body_html' => '<p>Nice</p>',
                'variants' => [['id' => 1, 'title' => 'Default Title', 'sku' => 'MUG', 'price' => '12.50', 'inventory_quantity' => 8]],
            ]]]),
        ]);

        $user = $this->proAdmin();
        $this->postJson('/api/v1/stores/shopify/connect', ['shop' => 'demo']);
        $state = StoreConnection::first()->state_token;

        $this->get('/api/v1/stores/shopify/callback?' . http_build_query($this->shopifyHmac([
            'shop' => 'demo.myshopify.com', 'code' => 'abc', 'state' => $state, 'timestamp' => '1700000000',
        ])))->assertRedirect('https://app.test/dashboard/settings/integrations?shopify=connected');

        $c = StoreConnection::first();
        $this->assertSame('active', $c->status);
        $this->assertSame('shpat_secret', $c->credential('access_token'));
        $this->assertNull($c->state_token);
        // The token is encrypted at rest, never plain in the column.
        $this->assertStringNotContainsString('shpat_secret', \DB::table('store_connections')->value('credentials'));

        $mug = Product::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('external_id', '111')->first();
        $this->assertSame('Mug', $mug->name);
        $this->assertEquals(8, $mug->quantity);
        $this->assertEquals(12.5, $mug->unit_price);
        $this->assertSame(1, $c->product_count);
    }

    public function test_a_store_live_on_another_account_cannot_be_claimed(): void
    {
        $other = Tenant::factory()->create();
        $this->activeShopify($other);
        $this->proAdmin();

        $this->postJson('/api/v1/stores/shopify/connect', ['shop' => 'demo'])->assertStatus(409);
    }

    // ------------------------------------------------------------ Shopify webhooks

    public function test_an_unsigned_webhook_is_refused_and_does_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->activeShopify($tenant);

        $this->postWebhook('products/update', ['id' => 1, 'title' => 'X', 'variants' => []], 'bogus')->assertStatus(401);
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_a_product_update_webhook_upserts_the_product(): void
    {
        $tenant = Tenant::factory()->create();
        $this->activeShopify($tenant);

        $this->postWebhook('products/update', [
            'id' => 5, 'title' => 'Hat', 'variants' => [['id' => 9, 'title' => 'Default Title', 'price' => '20.00', 'inventory_quantity' => 3]],
        ])->assertOk();

        $this->assertEquals(3, Product::withoutGlobalScopes()->where('external_id', '5')->value('quantity'));
    }

    public function test_a_paid_order_books_a_sale_once_without_double_deducting_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $this->activeShopify($tenant);
        // Shopify already took the unit: its level (4) is what we mirrored.
        Product::factory()->create(['tenant_id' => $tenant->id, 'external_id' => '5', 'quantity' => 4]);

        $order = ['id' => 700, 'name' => '#1001', 'created_at' => '2026-10-01T10:00:00Z',
            'line_items' => [['product_id' => 5, 'variant_id' => 9, 'quantity' => 1, 'price' => '20.00']]];

        $this->postWebhook('orders/paid', $order)->assertOk();
        $this->postWebhook('orders/paid', $order)->assertOk(); // redelivery

        $this->assertSame(1, Sale::withoutGlobalScopes()->where('external_order_id', 'shopify:700')->count());
        $this->assertEquals(4, Product::withoutGlobalScopes()->where('external_id', '5')->value('quantity'));
    }

    public function test_an_order_for_the_last_unit_is_still_recorded(): void
    {
        $tenant = Tenant::factory()->create();
        $this->activeShopify($tenant);
        Product::factory()->create(['tenant_id' => $tenant->id, 'external_id' => '5', 'quantity' => 0]);

        $this->postWebhook('orders/paid', ['id' => 701, 'line_items' => [['product_id' => 5, 'quantity' => 1, 'price' => '20.00']]])->assertOk();

        $this->assertSame(1, Sale::withoutGlobalScopes()->where('external_order_id', 'shopify:701')->count());
    }

    public function test_uninstalling_disconnects_and_drops_the_token(): void
    {
        $tenant = Tenant::factory()->create();
        $c = $this->activeShopify($tenant);

        $this->postWebhook('app/uninstalled', ['id' => 1])->assertOk();

        $c->refresh();
        $this->assertSame('disconnected', $c->status);
        $this->assertNull($c->credentials);
    }

    public function test_privacy_webhooks_are_acknowledged(): void
    {
        $this->postWebhook('customers/redact', ['shop_domain' => 'demo.myshopify.com'])->assertOk();
    }

    // ------------------------------------------------------------- WooCommerce

    private function wooConnection(Tenant $tenant): StoreConnection
    {
        return StoreConnection::create([
            'tenant_id' => $tenant->id, 'provider' => 'woocommerce', 'store_url' => 'https://shop.test',
            'status' => 'active', 'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'webhook_secret' => 'whsec'],
        ]);
    }

    private function wooWebhook(StoreConnection $c, string $topic, array $payload, ?string $sig = null)
    {
        $body = json_encode($payload);
        $sig ??= base64_encode(hash_hmac('sha256', $body, 'whsec', true));

        return $this->call('POST', "/api/v1/webhooks/woocommerce/{$c->uuid}", [], [], [], [
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => $sig, 'HTTP_X_WC_WEBHOOK_TOPIC' => $topic, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_woocommerce_connect_builds_the_approval_url(): void
    {
        $this->proAdmin();

        $url = $this->postJson('/api/v1/stores/woocommerce/connect', ['store_url' => 'shop.test'])->assertOk()->json('url');

        $this->assertStringStartsWith('https://shop.test/wc-auth/v1/authorize?', $url);
        $this->assertStringContainsString('callback_url=', $url);
        $this->assertStringContainsString('user_id=' . StoreConnection::first()->state_token, $url);
    }

    public function test_woocommerce_callback_stores_keys_and_syncs(): void
    {
        Http::fake([
            'shop.test/wp-json/wc/v3/webhooks' => Http::response([], 201),
            'shop.test/wp-json/wc/v3/products*' => Http::response([
                ['id' => 31, 'name' => 'Tee', 'type' => 'simple', 'sku' => 'TEE', 'price' => '15', 'stock_quantity' => 12, 'short_description' => ''],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $user = $this->proAdmin();
        $this->postJson('/api/v1/stores/woocommerce/connect', ['store_url' => 'shop.test']);
        $state = StoreConnection::first()->state_token;

        $this->postJson('/api/v1/stores/woocommerce/callback', [
            'user_id' => $state, 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'key_id' => 1,
        ])->assertOk();

        $c = StoreConnection::first();
        $this->assertSame('active', $c->status);
        $this->assertSame('ck_x', $c->credential('consumer_key'));
        $this->assertEquals(12, Product::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('external_id', '31')->value('quantity'));
    }

    public function test_the_woocommerce_callback_rejects_an_unknown_state(): void
    {
        $this->postJson('/api/v1/stores/woocommerce/callback', ['user_id' => 'nope', 'consumer_key' => 'a', 'consumer_secret' => 'b'])->assertStatus(404);
    }

    public function test_pasted_woocommerce_keys_are_verified_before_saving(): void
    {
        Http::fake(['shop.test/wp-json/wc/v3/products*' => Http::response(['message' => 'bad'], 401)]);
        $this->proAdmin();

        $this->postJson('/api/v1/stores/woocommerce/keys', ['store_url' => 'shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'])
            ->assertStatus(422);

        $this->assertSame(0, StoreConnection::count());
    }

    public function test_a_woocommerce_webhook_needs_the_right_signature(): void
    {
        $c = $this->wooConnection(Tenant::factory()->create());

        $this->wooWebhook($c, 'product.updated', ['id' => 1, 'name' => 'X', 'price' => '1'], 'wrong')->assertStatus(401);
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_a_processing_woocommerce_order_books_a_sale_but_a_pending_one_does_not(): void
    {
        $tenant = Tenant::factory()->create();
        $c = $this->wooConnection($tenant);
        Product::factory()->create(['tenant_id' => $tenant->id, 'external_id' => '31', 'quantity' => 10]);

        $order = fn (string $status) => ['id' => 900, 'number' => '900', 'status' => $status, 'date_created' => '2026-10-01T10:00:00',
            'line_items' => [['product_id' => 31, 'variation_id' => 0, 'quantity' => 2, 'price' => 15]]];

        $this->wooWebhook($c, 'order.created', $order('pending'))->assertOk();
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());

        $this->wooWebhook($c, 'order.updated', $order('processing'))->assertOk();
        $this->wooWebhook($c, 'order.updated', $order('completed'))->assertOk(); // later status change: no double booking

        $this->assertSame(1, Sale::withoutGlobalScopes()->where('external_order_id', 'woocommerce:900')->count());
        $this->assertEquals(10, Product::withoutGlobalScopes()->where('external_id', '31')->value('quantity'));
    }

    // ---------------------------------------------------------------- management

    public function test_a_tenant_can_list_and_disconnect_only_their_own_stores(): void
    {
        $user = $this->proAdmin();
        $mine = $this->wooConnection(Tenant::find($user->tenant_id));
        $theirs = $this->activeShopify(Tenant::factory()->create());

        $this->getJson('/api/v1/stores')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.credentials');

        $this->deleteJson("/api/v1/stores/{$theirs->id}")->assertStatus(403);
        $this->deleteJson("/api/v1/stores/{$mine->id}")->assertStatus(204);
    }
}
