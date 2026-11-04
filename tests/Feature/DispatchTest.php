<?php

namespace Tests\Feature;

use App\Models\Dispatch\Job;
use App\Models\Dispatch\Rider;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private Shop $shop;
    private Product $product;
    private string $payStatus = 'requires_payment';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.pay_dispatch.url' => 'https://pay.test', 'services.pay_dispatch.secret_key' => 'sk_test_x', 'services.pay_dispatch.webhook_secret' => 'whsec']);
        $this->artisan('dispatch:setup')->assertSuccessful();

        $this->tenant = Tenant::factory()->create(['name' => 'Akosua Boutique', 'plan' => 'pro', 'subscription_ends_at' => now()->addYear()]);
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
        $this->shop = Shop::create(['tenant_id' => $this->tenant->id, 'slug' => 'akosua', 'enabled' => true, 'pickup' => true, 'delivery' => true, 'notify_phone' => '0201112222',
            'rider_delivery' => true, 'pickup_address' => 'Osu, Oxford Street', 'pickup_lat' => 5.5560, 'pickup_lng' => -0.1830]);
        $this->product = new Product(['name' => 'Wig', 'quantity' => 5, 'unit_price' => 200]);
        $this->product->tenant_id = $this->tenant->id;
        $this->product->shop_visible = true;
        $this->product->save();

        Http::fake([
            'pay.test/v1/wallets' => Http::response(['id' => 'wal_1']),
            'pay.test/v1/payment_intents/pi_1' => fn () => Http::response(['id' => 'pi_1', 'status' => $this->payStatus]),
            'pay.test/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment', 'checkout_url' => 'https://pay.test/c/?t=abc']),
            'pay.test/v1/wallets/*/adjustments' => Http::response(['id' => 'wal_1']),
        ]);
    }

    private function order(array $over = []): ShopOrder
    {
        $this->postJson('/api/v1/public/shops/akosua/orders', $over + [
            'name' => 'Ama Mensah', 'phone' => '0244000111', 'fulfilment' => 'delivery', 'address' => 'East Legon, near the mall', 'landmark' => 'Blue gate',
            'payment_method' => 'momo_on_delivery', 'lat' => 5.6350, 'lng' => -0.1580, 'consent' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated();

        return ShopOrder::withoutGlobalScopes()->latest('id')->first();
    }

    private function confirmed(): ShopOrder
    {
        $order = $this->order();
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/shop/orders/{$order->id}", ['status' => 'confirmed'])->assertOk();

        return $order->refresh();
    }

    private function activeRider(string $email = 'kofi@rider.test'): array
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/admin/dispatch/riders', ['name' => 'Kofi Rider', 'phone' => '0277000111', 'email' => $email])->assertCreated()->json('id');
        Rider::find($id)->update(['status' => 'active', 'id_number' => 'GHA-1', 'id_photo_path' => 'x', 'selfie_path' => 'y', 'online' => true]);

        return [Rider::find($id), User::where('email', $email)->first()];
    }

    public function test_the_delivery_fee_comes_from_the_admin_bands_and_the_server(): void
    {
        $q = $this->getJson('/api/v1/public/shops/akosua/delivery-quote?lat=5.6350&lng=-0.1580')->assertOk();
        $this->assertTrue($q->json('available'));
        $this->assertContains($q->json('fee'), [15, 25, 40]);

        $this->getJson('/api/v1/public/shops/akosua/delivery-quote?lat=6.69&lng=-1.62')->assertJsonPath('available', false);   // Kumasi
        $order = $this->order();
        $this->assertSame((int) ($q->json('fee') * 100), $order->delivery_fee);
        $this->assertSame('momo_on_delivery', $order->payment_method);
    }

    public function test_pay_on_arrival_is_refused_outside_accra_or_for_pickup(): void
    {
        $base = ['name' => 'Ama', 'phone' => '0244000111', 'fulfilment' => 'delivery', 'address' => 'Kumasi', 'payment_method' => 'momo_on_delivery', 'consent' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
        $this->postJson('/api/v1/public/shops/akosua/orders', $base + ['lat' => 6.69, 'lng' => -1.62])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->postJson('/api/v1/public/shops/akosua/orders', ['fulfilment' => 'pickup'] + $base + ['lat' => 5.63, 'lng' => -0.15])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_a_rider_is_only_requested_for_confirmed_pay_on_arrival_orders(): void
    {
        $order = $this->order();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertStatus(422);   // not confirmed yet
        $this->patchJson("/api/v1/shop/orders/{$order->id}", ['status' => 'confirmed'])->assertOk();
        $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertCreated()->assertJsonPath('status', 'searching');
        $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertStatus(422);   // already requested
    }

    public function test_the_whole_delivery_from_offer_to_handover(): void
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertCreated()->json('id');
        $job = Job::find($jobId);

        // Money is fixed up front: 20,000 goods; fee from bands; rider gets 80% of it.
        $this->assertSame(20000, $job->goods_amount);
        $this->assertSame((int) round($job->delivery_fee * 0.8), $job->rider_share);
        $this->assertSame(20000 + $job->delivery_fee, $job->total());

        Sanctum::actingAs($riderUser);
        $this->getJson('/api/v1/rider/jobs')->assertJsonCount(1, 'offers')->assertJsonPath('offers.0.dropoff.phone', null);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk()->assertJsonPath('status', 'assigned');

        // A second rider cannot take it.
        [$other, $otherUser] = $this->activeRider('ama@rider.test');
        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertStatus(422);

        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertStatus(422);   // not picked up yet
        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk()->assertJsonPath('status', 'picked_up');
        $sms = SmsLog::where('recipient', '233244000111')->where('message', 'like', '%Give the rider this code%')->firstOrFail();
        preg_match('/code after paying: (\d{4})/', $sms->message, $m);
        $code = $m[1];

        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk()->assertJsonPath('checkout_url', 'https://pay.test/c/?t=abc');
        // The payment carries the split: shop gets the goods, rider their share.
        Http::assertSent(function ($r) use ($job) {
            if (! str_ends_with($r->url(), '/v1/payment_intents') || $r->method() !== 'POST') {
                return false;
            }
            $splits = collect($r['splits'])->keyBy('owner_ref');

            return $r['amount'] === $job->total() && $splits["shop:{$this->tenant->id}"]['amount'] === 20000 && $splits['rider:'.$job->fresh()->rider_id]['amount'] === $job->rider_share;
        });

        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => $code])->assertStatus(422)->assertJsonValidationErrors('job');   // not paid
        $this->payStatus = 'succeeded';
        $this->getJson("/api/v1/rider/jobs/{$jobId}/payment")->assertJsonPath('pay_status', 'paid');
        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => '0000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => $code])->assertOk()->assertJsonPath('status', 'delivered');

        $this->assertSame('completed', ShopOrder::withoutGlobalScopes()->find($order->id)->status);
        $this->assertSame(1, Sale::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $rider->fresh()->deliveries);
        $this->assertSame(4.0, (float) Product::withoutGlobalScopes()->find($this->product->id)->quantity, 'stock came off');
    }

    public function test_a_failed_payment_attempt_gets_a_fresh_payment_next_time(): void
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk();
        $this->payStatus = 'failed';
        $this->getJson("/api/v1/rider/jobs/{$jobId}/payment")->assertJsonPath('pay_status', 'unpaid');
        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payment_intents') && $r->method() === 'POST' && $r['reference'] === 'job-'.Job::find($jobId)->code.'-2');
    }

    public function test_the_customer_tracking_link_shows_progress_and_only_what_they_need(): void
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        $sms = SmsLog::where('recipient', '233244000111')->where('message', 'like', '%Follow it here:%')->firstOrFail();
        preg_match('#/track/([A-Za-z0-9]+)#', $sms->message, $m);
        $token = $m[1];

        $this->getJson("/api/v1/public/track/{$token}")->assertOk()->assertJsonPath('status', 'searching')->assertJsonPath('rider', null)->assertJsonPath('shop', 'Akosua Boutique');
        $this->getJson('/api/v1/public/track/'.str_repeat('x', 32))->assertNotFound();

        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk();
        $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('status', 'assigned')->assertJsonPath('rider.name', 'Kofi')->assertJsonPath('code', null);

        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk();
        $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('status', 'picked_up')->assertJsonPath('code', null)->assertJsonPath('pay_url', null);

        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk();
        $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('pay_url', 'https://pay.test/c/?t=abc')->assertJsonPath('code', null);

        $this->payStatus = 'succeeded';
        $this->getJson("/api/v1/rider/jobs/{$jobId}/payment")->assertOk();
        $paid = $this->getJson("/api/v1/public/track/{$token}")->assertOk();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $paid->json('code'));
        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => $paid->json('code')])->assertOk();
        $done = $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('status', 'delivered');
        $this->assertNull($done->json('rider'));
        $this->assertNull($done->json('code'));
    }

    private function deliveredJob(): array
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        $sms = SmsLog::where('recipient', '233244000111')->where('message', 'like', '%Follow it here:%')->firstOrFail();
        preg_match('#/track/([A-Za-z0-9]+)#', $sms->message, $m);
        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk();
        $this->payStatus = 'succeeded';
        $this->getJson("/api/v1/rider/jobs/{$jobId}/payment")->assertOk();
        $code = $this->getJson("/api/v1/public/track/{$m[1]}")->json('code');
        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => $code])->assertOk();

        return [$jobId, $m[1], $rider, $riderUser];
    }

    public function test_customers_rate_a_delivery_once_and_riders_see_their_average(): void
    {
        [$jobId, $token, $rider, $riderUser] = $this->deliveredJob();
        $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('can_rate', true)->assertJsonPath('rating', null);
        $this->postJson("/api/v1/public/track/{$token}/rating", ['rating' => 6])->assertStatus(422);
        $this->postJson("/api/v1/public/track/{$token}/rating", ['rating' => 5, 'comment' => 'Fast and polite'])->assertCreated();
        $this->postJson("/api/v1/public/track/{$token}/rating", ['rating' => 1])->assertStatus(422);   // once only
        $this->getJson("/api/v1/public/track/{$token}")->assertJsonPath('can_rate', false)->assertJsonPath('rating.value', 5);

        Sanctum::actingAs($riderUser);
        $this->getJson('/api/v1/rider/me')->assertJsonPath('rating.avg', 5)->assertJsonPath('rating.count', 1);
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/dispatch/riders/{$rider->id}/ratings")->assertOk()->assertJsonPath('data.0.comment', 'Fast and polite')->assertJsonPath('summary.count', 1);
    }

    public function test_a_delivery_that_is_not_finished_cannot_be_rated(): void
    {
        $order = $this->confirmed();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertCreated();
        $sms = SmsLog::where('message', 'like', '%Follow it here:%')->firstOrFail();
        preg_match('#/track/([A-Za-z0-9]+)#', $sms->message, $m);
        $this->postJson("/api/v1/public/track/{$m[1]}/rating", ['rating' => 5])->assertStatus(422);
    }

    public function test_the_owner_statement_lists_delivered_orders_with_totals_and_csv(): void
    {
        $this->deliveredJob();
        Sanctum::actingAs($this->owner);
        Http::fake(['pay.test/v1/wallets/*/withdrawals' => Http::response(['data' => []])] + [
            'pay.test/*' => Http::response([]),
        ]);
        $r = $this->getJson('/api/v1/wallet/statement?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $this->assertSame(1, $r->json('totals.deliveries'));
        $this->assertEquals(200.0, $r->json('totals.you_received'));
        $this->assertEquals($r->json('rows.0.customer_paid'), $r->json('rows.0.goods') + $r->json('rows.0.delivery_fee'));
        $csv = $this->get('/api/v1/wallet/statement?format=csv&from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $this->assertStringContainsString('You received', $csv->streamedContent());
        $this->assertStringContainsString('Total', $csv->streamedContent());

        // Another workspace never sees these rows, and a non-admin cannot open the statement at all.
        $other = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $other->id, 'role' => 'Administrator']));
        $this->getJson('/api/v1/wallet/statement?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertJsonPath('totals.deliveries', 0);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']));
        $this->getJson('/api/v1/wallet/statement')->assertForbidden();
    }

    public function test_a_shop_owner_can_also_become_a_rider_with_the_same_account(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('registered', false);
        $this->getJson('/api/v1/rider/jobs')->assertForbidden();   // not a rider until they register
        $this->postJson('/api/v1/rider/register', ['phone' => '0244111222'])->assertCreated()->assertJsonPath('status', 'invited')->assertJsonPath('registered', true);
        $this->postJson('/api/v1/rider/register', ['phone' => '0244111222'])->assertCreated();   // safe to repeat
        $this->assertSame(1, Rider::where('user_id', $this->owner->id)->count());
        $this->getJson('/api/v1/rider/me')->assertJsonPath('registered', true)->assertJsonPath('rating.count', 0);
        $this->postJson('/api/v1/rider/online', ['online' => true])->assertForbidden();   // still needs approval
        $this->assertSame('Administrator', $this->owner->fresh()->role, 'their shop role is untouched');
    }

    public function test_a_brand_new_zinnvy_account_gets_a_rider_login_not_a_shop_workspace(): void
    {
        \Illuminate\Support\Facades\Cache::put('zinnvy_rider_signup:abc123', ['sub' => 'sub-new', 'email' => 'new.rider@example.test', 'name' => 'New Rider'], now()->addMinutes(5));
        $r = $this->postJson('/api/v1/auth/zinnvy/rider-signup', ['code' => 'abc123'])->assertCreated();
        $this->assertSame('Rider', $r->json('user.role'));
        $this->assertSame((int) \App\Services\Dispatch\Settings::get('platform_tenant_id'), (int) User::where('email', 'new.rider@example.test')->value('tenant_id'));
        $this->assertSame(0, Tenant::where('name', 'New Rider')->count(), 'no shop workspace was created');
        $this->postJson('/api/v1/auth/zinnvy/rider-signup', ['code' => 'abc123'])->assertStatus(404);   // one use
        $token = $r->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/rider/register', ['phone' => '0244999888'])->assertCreated();
    }

    public function test_staff_can_invite_a_rider_who_already_has_an_account(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/dispatch/riders', ['name' => 'Akosua', 'phone' => '0277000999', 'email' => $this->owner->email])->assertCreated();
        $this->assertSame('Administrator', $this->owner->fresh()->role);
        $this->assertSame($this->owner->id, Rider::where('email', $this->owner->email)->value('user_id'));
    }

    public function test_wrong_codes_lock_out_after_a_few_tries(): void
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/collect")->assertOk();
        $this->payStatus = 'succeeded';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => '1111'])->assertStatus(422);
        }
        $this->postJson("/api/v1/rider/jobs/{$jobId}/deliver", ['code' => '1111'])->assertStatus(422)->assertSee('Too many wrong codes');
    }

    public function test_a_failed_delivery_pays_the_rider_a_trip_fee_and_never_a_paid_job(): void
    {
        $order = $this->confirmed();
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        Sanctum::actingAs($riderUser);
        $this->postJson("/api/v1/rider/jobs/{$jobId}/accept")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/pickup")->assertOk();
        $this->postJson("/api/v1/rider/jobs/{$jobId}/fail", ['reason' => 'Customer could not pay'])->assertOk()->assertJsonPath('status', 'failed');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/adjustments') && $r['amount'] === 500 && $r['key'] === 'tripfee-'.Job::find($jobId)->code);
        $this->assertFalse((bool) Job::find($jobId)->trip_fee_due);
        $this->assertNotSame('completed', ShopOrder::withoutGlobalScopes()->find($order->id)->status);

        // The owner can ask again after a failed trip.
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->assertCreated();
    }

    public function test_unanswered_offers_lapse_and_are_offered_again(): void
    {
        $order = $this->confirmed();
        [$rider] = $this->activeRider();
        Sanctum::actingAs($this->owner);
        $jobId = $this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id');
        $this->assertSame(1, Job::find($jobId)->offers()->where('status', 'offered')->count());

        $this->travel(3)->minutes();
        $this->artisan('dispatch:tick')->assertSuccessful();
        $job = Job::find($jobId);
        $this->assertSame(2, $job->offer_rounds, 'a second round was offered');
        $this->assertSame('offered', $job->offers()->first()->status);
    }

    public function test_riders_cannot_use_workspace_or_admin_apis_and_owners_cannot_use_rider_apis(): void
    {
        [$rider, $riderUser] = $this->activeRider();
        Sanctum::actingAs($riderUser);
        $this->getJson('/api/v1/admin/dispatch/riders')->assertForbidden();
        $this->getJson('/api/v1/wallet')->assertForbidden();   // workspace admins only
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/rider/jobs')->assertForbidden();
    }

    public function test_staff_set_commission_and_it_only_affects_new_jobs(): void
    {
        $order = $this->confirmed();
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/dispatch/settings', ['rider_fee_percent' => 70, 'goods_commission_percent' => 5])->assertOk()->assertJsonPath('rider_fee_percent', 70);
        $this->putJson('/api/v1/admin/dispatch/settings', ['rider_fee_percent' => 120])->assertStatus(422);

        Sanctum::actingAs($this->owner);
        $job = Job::find($this->postJson("/api/v1/shop/orders/{$order->id}/rider")->json('id'));
        $this->assertSame((int) round($job->delivery_fee * 0.7), $job->rider_share);
        $this->assertSame(1000, $job->goods_commission);   // 5% of 200
        $this->assertSame(19000, $job->ownerShare());
        $this->assertSame(70.0, (float) $job->rates['rider_fee_percent']);
    }

    public function test_a_rider_must_be_approved_with_documents_before_riding(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/admin/dispatch/riders', ['name' => 'New Rider', 'phone' => '0277000222', 'email' => 'new@rider.test'])->assertCreated()->json('id');
        $this->patchJson("/api/v1/admin/dispatch/riders/{$id}", ['status' => 'active'])->assertStatus(422);

        Sanctum::actingAs(User::where('email', 'new@rider.test')->first());
        $this->postJson('/api/v1/rider/online', ['online' => true])->assertForbidden();
    }

    public function test_the_admin_console_reaches_dispatch_with_an_identity_staff_token(): void
    {
        config(['zinnvy.accounts_url' => 'https://accounts.test']);
        Http::fake(['accounts.test/api/admin/me' => fn ($r) => $r->hasHeader('Authorization', 'Bearer staff-token')
            ? Http::response(['id' => 's1', 'is_superadmin' => false]) : Http::response([], 401)] + [
            'pay.test/*' => Http::response([]),
        ]);
        $this->getJson('/api/v1/staff/dispatch/overview')->assertStatus(401);
        $this->getJson('/api/v1/staff/dispatch/overview', ['Authorization' => 'Bearer nope'])->assertStatus(401);
        $this->getJson('/api/v1/staff/dispatch/overview', ['Authorization' => 'Bearer staff-token'])->assertOk()->assertJsonStructure(['riders', 'jobs']);
        // Staff can look; only superadmins change commission.
        $this->putJson('/api/v1/staff/dispatch/settings', ['rider_fee_percent' => 50], ['Authorization' => 'Bearer staff-token'])->assertStatus(403);
    }
}
