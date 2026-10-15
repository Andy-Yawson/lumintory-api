<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopEvent;
use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shop\ShopInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    private Tenant $tenant;
    private User $admin;
    private Shop $shop;
    private Product $dress;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'subscription_ends_at' => now()->addYear()]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
        $this->shop = Shop::create(['tenant_id' => $this->tenant->id, 'slug' => 'closet', 'enabled' => true, 'pickup' => true]);
        $this->dress = new Product(['name' => 'Silk Dress', 'quantity' => 0, 'unit_price' => 250]);
        $this->dress->tenant_id = $this->tenant->id;
        $this->dress->shop_visible = true;
        $this->dress->save();
    }

    private function send(array $body, string $ua = self::PHONE_UA)
    {
        return $this->call('POST', '/api/v1/public/shops/closet/events', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => $ua], json_encode($body));
    }

    public function test_events_are_stored_anonymously_with_source_and_device(): void
    {
        $this->send(['visitor' => 'v-aaaaaaaa', 'source' => ['utm_source' => 'whatsapp', 'utm_campaign' => 'eid'], 'events' => [
            ['type' => 'view'], ['type' => 'product_view', 'product_id' => $this->dress->id], ['type' => 'nonsense'],
            ['type' => 'search', 'search' => 'Wedding Dress', 'results' => 0],
        ]])->assertNoContent();

        $this->assertSame(3, ShopEvent::count());
        $e = ShopEvent::where('type', 'product_view')->first();
        $this->assertSame(['whatsapp', 'eid', 'mobile', $this->dress->id], [$e->source, $e->campaign, $e->device, $e->product_id]);
        $this->assertSame(64, strlen($e->ip_hash), 'hashed, never the raw address');
        $this->assertStringNotContainsString('127.0.0.1', json_encode(ShopEvent::all()));
    }

    public function test_bots_other_workspaces_products_and_missing_visitors_are_ignored(): void
    {
        $this->send(['visitor' => 'v-bbbbbbbb', 'events' => [['type' => 'view']]], 'facebookexternalhit/1.1')->assertNoContent();
        $this->send(['events' => [['type' => 'view']]])->assertNoContent();
        $this->assertSame(0, ShopEvent::count());

        $other = new Product(['name' => 'Not yours', 'quantity' => 1, 'unit_price' => 1]);
        $other->tenant_id = Tenant::factory()->create()->id;
        $other->save();
        $this->send(['visitor' => 'v-cccccccc', 'events' => [['type' => 'product_view', 'product_id' => $other->id]]]);
        $this->assertNull(ShopEvent::first()->product_id);
    }

    public function test_sources_are_classified(): void
    {
        $this->assertSame('instagram', ShopInsights::classifySource(null, 'https://l.instagram.com/?u=x'));
        $this->assertSame('facebook', ShopInsights::classifySource(null, null, false, 'fbclid'));
        $this->assertSame('marketplace', ShopInsights::classifySource(null, null, true));
        $this->assertSame('direct', ShopInsights::classifySource(null, null));
        $this->assertSame('other', ShopInsights::classifySource(null, 'https://someblog.com/post'));
    }

    public function test_insights_funnel_products_sources_searches_and_highlights(): void
    {
        foreach (range(1, 12) as $i) {
            $this->send(['visitor' => "v-whatsapp-{$i}", 'source' => ['utm_source' => 'whatsapp'], 'events' => [['type' => 'view'], ['type' => 'product_view', 'product_id' => $this->dress->id]]]);
        }
        $this->send(['visitor' => 'v-insta-1', 'source' => ['referrer' => 'https://instagram.com'], 'events' => [['type' => 'view'], ['type' => 'add_to_cart', 'product_id' => $this->dress->id], ['type' => 'checkout'], ['type' => 'search', 'search' => 'wedding dress', 'results' => 0]]]);
        ShopOrder::withoutGlobalScopes()->forceCreate(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'number' => 1001, 'customer_name' => 'A', 'customer_phone' => '0241234567',
            'fulfilment' => 'pickup', 'subtotal' => 250, 'item_count' => 1, 'source' => ['utm_source' => 'instagram']]);

        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/api/v1/shop/insights?range=7d')->assertOk()->json();

        $this->assertSame(13, $r['totals']['visitors']);
        $this->assertSame([13, 12, 1, 1, 1], array_column($r['funnel'], 'count'));
        $this->assertSame(['whatsapp', 12, 0], [$r['sources'][0]['source'], $r['sources'][0]['visitors'], $r['sources'][0]['orders']]);
        $this->assertSame(1, collect($r['sources'])->firstWhere('source', 'instagram')['orders']);
        $this->assertSame(['Silk Dress', 12, false], [$r['products'][0]['name'], $r['products'][0]['views'], $r['products'][0]['in_stock']]);
        $this->assertTrue($r['searches'][0]['no_results']);
        $this->assertCount(7, $r['daily']);
        $text = implode(' ', array_column($r['highlights'], 'text'));
        $this->assertStringContainsString('came from WhatsApp', $text);
        $this->assertStringContainsString('out of stock', $text);
        $this->assertStringContainsString('"wedding dress"', $text);
    }

    public function test_insights_are_pro_only_and_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']));
        $this->getJson('/api/v1/shop/insights')->assertForbidden();

        $this->tenant->update(['plan' => 'basic']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/shop/insights')->assertForbidden();
    }

    public function test_owner_pixels_are_validated_and_sent_to_the_shop(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/shop', ['meta_pixel_id' => 'abc'])->assertStatus(422);
        $this->putJson('/api/v1/shop', ['ga_measurement_id' => 'UA-123'])->assertStatus(422);
        $this->putJson('/api/v1/shop', ['meta_pixel_id' => '1234567890123456', 'ga_measurement_id' => 'G-ABC123XYZ'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/shops/closet')->assertJsonPath('tracking', ['meta' => '1234567890123456', 'ga' => 'G-ABC123XYZ']);
    }

    public function test_weekly_digest_emails_admins(): void
    {
        $this->send(['visitor' => 'v-dddddddd', 'events' => [['type' => 'view']]]);
        $this->artisan('shop:weekly-digest')->expectsOutputToContain('Sent 1 digest email(s) for 1 shop(s).')->assertSuccessful();
    }
}
