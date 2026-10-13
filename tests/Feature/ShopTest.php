<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shop\ShopOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Queue::fake();
        $this->tenant = Tenant::factory()->create(['name' => 'Earle’s Closet', 'plan' => 'pro', 'subscription_ends_at' => now()->addYear(), 'settings' => ['currency_symbol' => 'GH₵']]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/shop', ['enabled' => true, 'notify_phone' => '0241112222'])->assertOk();
        $this->shop = Shop::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->app['auth']->forgetGuards(); // public calls below are anonymous
    }

    private function product(string $name, float $qty = 10, float $price = 100, bool $visible = true, ?Tenant $t = null): Product
    {
        $p = new Product(['name' => $name, 'quantity' => $qty, 'unit_price' => $price]);
        $p->tenant_id = ($t ?? $this->tenant)->id;
        $p->shop_visible = $visible;
        $p->save();

        return $p;
    }

    private function order(array $items, array $extra = [])
    {
        return $this->postJson("/api/v1/public/shops/{$this->shop->slug}/orders", $extra + [
            'name' => 'Ama Mensah', 'phone' => '024 123 4567', 'fulfilment' => 'pickup', 'consent' => true, 'items' => $items,
        ]);
    }

    public function test_a_new_shop_gets_a_permanent_id_and_a_readable_link(): void
    {
        $this->assertMatchesRegularExpression('/^\d{5}-\d{5}-\d{4}-\d{4}$/', $this->shop->public_id);
        $this->assertSame('earles-closet', $this->shop->slug);
        $this->getJson("/api/v1/public/shops/{$this->shop->public_id}")->assertOk()->assertJsonPath('name', 'Earle’s Closet');
        $this->getJson('/api/v1/public/shops/earles-closet')->assertOk()->assertJsonPath('urls.readable', 'https://shop.zinnvy.com/earles-closet');
    }

    public function test_only_visible_products_show_and_never_the_private_fields(): void
    {
        $this->product('Silk Dress', 3, 250);
        $this->product('Secret Stock', 50, 10, visible: false);

        $res = $this->getJson('/api/v1/public/shops/earles-closet/products')->assertOk()->assertJsonCount(1, 'data');
        $item = $res->json('data.0');
        $this->assertSame('Silk Dress', $item['name']);
        $this->assertSame('low', $item['availability']);
        $this->assertSame(3, $item['left']);
        foreach (['quantity', 'sku', 'tenant_id', 'min_stock_threshold', 'lead_time_days', 'shop_visible'] as $private) {
            $this->assertArrayNotHasKey($private, $item);
        }
    }

    public function test_a_shop_is_hidden_when_off_unlisted_or_not_on_pro(): void
    {
        $this->shop->update(['enabled' => false]);
        $this->getJson('/api/v1/public/shops/earles-closet')->assertNotFound();

        $this->shop->update(['enabled' => true]);
        $this->shop->forceFill(['unlisted_at' => now()])->save();
        $this->getJson('/api/v1/public/shops/earles-closet')->assertNotFound();

        $this->shop->forceFill(['unlisted_at' => null])->save();
        $this->tenant->update(['plan' => 'basic']);
        $this->getJson('/api/v1/public/shops/earles-closet')->assertNotFound();

        $this->tenant->update(['plan' => 'pro', 'subscription_ends_at' => now()->subDay()]);
        $this->getJson('/api/v1/public/shops/earles-closet')->assertNotFound();
    }

    public function test_basic_workspaces_cannot_switch_the_shop_on(): void
    {
        $basic = Tenant::factory()->create(['plan' => 'basic']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $basic->id, 'role' => 'Administrator']));
        $this->putJson('/api/v1/shop', ['enabled' => true])->assertForbidden();
    }

    public function test_slugs_are_validated(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/shop', ['slug' => 'Bad Slug!'])->assertStatus(422);
        $this->putJson('/api/v1/shop', ['slug' => 'checkout'])->assertStatus(422);
        $other = Shop::create(['tenant_id' => Tenant::factory()->create()->id, 'slug' => 'taken-name']);
        $this->putJson('/api/v1/shop', ['slug' => $other->slug])->assertStatus(422);
        $this->putJson('/api/v1/shop', ['slug' => 'earle-closet-accra'])->assertOk()->assertJsonPath('urls.readable', 'https://shop.zinnvy.com/earle-closet-accra');
    }

    public function test_an_order_uses_database_prices_and_never_touches_stock(): void
    {
        $dress = $this->product('Silk Dress', 3, 250);

        $this->order([['product_id' => $dress->id, 'quantity' => 2, 'unit_price' => 1]])
            ->assertCreated()->assertJsonPath('number', 1001)->assertJsonPath('subtotal', 500);

        $order = ShopOrder::withoutGlobalScopes()->with('items')->firstOrFail();
        $this->assertSame('0241234567', $order->customer_phone);
        $this->assertEquals(250, $order->items[0]->unit_price);
        $this->assertEquals(3, $dress->fresh()->quantity, 'no stock change until it becomes a sale');

        $this->assertTrue(SmsLog::where('recipient', '233241112222')->exists(), 'owner SMS');
        $this->assertTrue(SmsLog::where('recipient', '233241234567')->exists(), 'customer SMS');

        $this->order([['product_id' => $dress->id, 'quantity' => 1]])->assertJsonPath('number', 1002);
    }

    public function test_orders_are_refused_for_hidden_sold_out_or_too_many_items(): void
    {
        $hidden = $this->product('Hidden', 5, 10, visible: false);
        $soldOut = $this->product('Gone', 0, 10);
        $few = $this->product('Few', 2, 10);
        $foreign = $this->product('Not yours', 9, 10, t: Tenant::factory()->create());

        $this->order([['product_id' => $hidden->id, 'quantity' => 1]])->assertStatus(422);
        $this->order([['product_id' => $foreign->id, 'quantity' => 1]])->assertStatus(422);
        $this->order([['product_id' => $soldOut->id, 'quantity' => 1]])->assertStatus(422)->assertJsonFragment(['Gone is sold out.']);
        $this->order([['product_id' => $few->id, 'quantity' => 3]])->assertStatus(422)->assertJsonFragment(['Only 2 Few left.']);
        $this->assertSame(0, ShopOrder::withoutGlobalScopes()->count());
    }

    public function test_products_with_options_need_one_chosen(): void
    {
        $shirt = $this->product('Shirt', 0, 50);
        $red = ProductVariation::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'product_id' => $shirt->id, 'name' => 'Red', 'quantity' => 4, 'unit_price' => 60]);

        $this->order([['product_id' => $shirt->id, 'quantity' => 1]])->assertStatus(422);
        $this->order([['product_id' => $shirt->id, 'variation_id' => $red->id, 'quantity' => 1]])->assertCreated()->assertJsonPath('subtotal', 60);
    }

    public function test_consent_delivery_address_and_offered_options_are_required(): void
    {
        $p = $this->product('Bag');
        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['consent' => false])->assertStatus(422)->assertJsonValidationErrors('consent');
        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['fulfilment' => 'delivery'])->assertStatus(422); // shop is pickup-only
        $this->shop->update(['delivery' => true]);
        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['fulfilment' => 'delivery'])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_spam_guards_honeypot_blocked_numbers_and_daily_cap(): void
    {
        $p = $this->product('Bag', 100);

        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['website' => 'http://spam'])->assertCreated();
        $this->assertSame(0, ShopOrder::withoutGlobalScopes()->count(), 'honeypot: looks fine, saves nothing');

        $this->shop->forceFill(['blocked_phones' => ['+233 24 123 4567']])->save();
        $this->order([['product_id' => $p->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->shop->forceFill(['blocked_phones' => []])->save();
        for ($i = 0; $i < ShopOrderService::MAX_ORDERS_PER_PHONE_PER_DAY; $i++) {
            ShopOrder::withoutGlobalScopes()->forceCreate([
                'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'number' => 2000 + $i, 'customer_name' => 'x',
                'customer_phone' => '0241234567', 'fulfilment' => 'pickup', 'subtotal' => 1, 'item_count' => 1,
            ]);
        }
        $this->order([['product_id' => $p->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_owner_converts_an_order_into_sales(): void
    {
        $dress = $this->product('Silk Dress', 3, 250);
        $this->order([['product_id' => $dress->id, 'quantity' => 2]])->assertCreated();
        $id = ShopOrder::withoutGlobalScopes()->firstOrFail()->id;

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/shop/orders/{$id}", ['status' => 'contacted'])->assertOk()->assertJsonPath('status', 'contacted');
        $this->postJson("/api/v1/shop/orders/{$id}/convert", ['payment_method' => 'Mobile Money'])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertEquals(1, $dress->fresh()->quantity);
        $sale = Sale::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Online order #1001', $sale->notes);
        $this->assertSame('Ama Mensah', Customer::withoutGlobalScopes()->find($sale->customer_id)->name);
        $this->postJson("/api/v1/shop/orders/{$id}/convert", ['payment_method' => 'Cash'])->assertStatus(422);
    }

    public function test_converting_without_enough_stock_is_refused_cleanly(): void
    {
        $dress = $this->product('Silk Dress', 2, 250);
        $this->order([['product_id' => $dress->id, 'quantity' => 2]])->assertCreated();
        $dress->update(['quantity' => 1]); // sold in store meanwhile
        $id = ShopOrder::withoutGlobalScopes()->firstOrFail()->id;

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/shop/orders/{$id}/convert", ['payment_method' => 'Cash'])->assertStatus(422);
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame('new', ShopOrder::withoutGlobalScopes()->find($id)->status);
    }

    public function test_owners_only_see_their_own_orders(): void
    {
        $p = $this->product('Bag');
        $this->order([['product_id' => $p->id, 'quantity' => 1]])->assertCreated();
        $id = ShopOrder::withoutGlobalScopes()->firstOrFail()->id;

        $other = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $other->id, 'role' => 'Administrator']));
        $this->getJson("/api/v1/shop/orders/{$id}")->assertNotFound();
        $this->getJson('/api/v1/shop/orders')->assertJsonCount(0, 'data');
    }

    public function test_visibility_switch_and_list_all_with_photos(): void
    {
        $a = $this->product('A', visible: false);
        $b = $this->product('B', visible: false);
        \App\Models\ProductImage::create(['tenant_id' => $this->tenant->id, 'product_id' => $b->id, 'path' => 'x.jpg', 'position' => 0]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/shop/visibility', ['with_photos' => true, 'visible' => true])->assertOk()->assertJsonPath('changed', 1);
        $this->assertFalse($a->fresh()->shop_visible);
        $this->assertTrue($b->fresh()->shop_visible);
        $this->postJson('/api/v1/shop/visibility', ['ids' => [$a->id], 'visible' => true])->assertJsonPath('changed', 1);
    }

    public function test_marketplace_lists_live_shops_that_opted_in(): void
    {
        $this->product('Silk Dress');
        $other = Tenant::factory()->create(['plan' => 'pro']);
        Shop::create(['tenant_id' => $other->id, 'slug' => 'other', 'enabled' => true, 'in_marketplace' => false]);
        $this->product('Hidden from marketplace', t: $other);

        $this->getJson('/api/v1/public/marketplace/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop.slug', 'earles-closet');
        $this->getJson('/api/v1/public/shops/other')->assertOk(); // still reachable by its own link
        $this->getJson('/api/v1/public/marketplace/shops')->assertJsonCount(1, 'data');
    }

    public function test_admin_can_unlist_a_shop(): void
    {
        $platform = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $platform->id, 'role' => 'SuperAdmin']));
        $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/shop/moderation", ['unlisted' => true, 'reason' => 'Counterfeit goods'])->assertOk()->assertJsonPath('live', false);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/shops/earles-closet')->assertNotFound();
    }

    public function test_campaign_source_is_kept_on_the_order(): void
    {
        $p = $this->product('Bag');
        $this->order([['product_id' => $p->id, 'quantity' => 1]], ['source' => ['utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'eid-sale', 'evil' => 'x']])->assertCreated();

        $order = ShopOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'eid-sale'], $order->source);
        $this->assertSame('instagram / social (eid-sale)', ShopOrderService::sourceLabel($order->source));
    }

    public function test_feed_lists_full_products_for_one_shop_or_the_marketplace(): void
    {
        $this->product('Silk Dress', 3, 250);
        $this->product('Hidden', visible: false);

        $this->getJson('/api/v1/public/feed?shop=earles-closet')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop.currency', 'GHS');
        $this->getJson('/api/v1/public/feed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/feed?shop=nope')->assertNotFound();
    }

    public function test_momo_details_are_only_revealed_after_ordering(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/shop', ['momo_number' => '024 555 0000'])->assertStatus(422)->assertJsonValidationErrors('momo_network');
        $this->putJson('/api/v1/shop', ['momo_network' => 'Vodafone', 'momo_number' => '0245550000'])->assertStatus(422);
        $this->putJson('/api/v1/shop', ['momo_network' => 'MTN', 'momo_number' => '+233 24 555 0000', 'momo_name' => 'Earle Boutique'])
            ->assertOk()->assertJsonPath('momo_number', '0245550000');
        $this->app['auth']->forgetGuards();

        $shopJson = $this->getJson('/api/v1/public/shops/earles-closet')->assertOk()->json();
        $this->assertTrue($shopJson['accepts_momo']);
        $this->assertStringNotContainsString('0245550000', json_encode($shopJson), 'number not on public pages');

        $p = $this->product('Bag');
        $this->order([['product_id' => $p->id, 'quantity' => 1]])->assertCreated()
            ->assertJsonPath('momo.network', 'MTN')->assertJsonPath('momo.number', '0245550000')->assertJsonPath('momo.name', 'Earle Boutique');

        $sms = SmsLog::where('recipient', '233241234567')->latest('id')->value('message');
        $this->assertStringContainsString('After they confirm, pay by MTN MoMo 0245550000 (Earle Boutique), reference #1001.', $sms);
    }

    public function test_order_alert_sms_falls_back_to_the_whatsapp_number(): void
    {
        $this->shop->update(['notify_phone' => null, 'whatsapp' => '0207778888']);
        $p = $this->product('Bag');
        $this->order([['product_id' => $p->id, 'quantity' => 1]])->assertCreated();
        $this->assertTrue(SmsLog::where('recipient', '233207778888')->where('message', 'like', 'New shop order%')->exists());
    }
}
