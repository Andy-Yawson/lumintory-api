<?php

namespace Tests\Feature;

use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pay.url' => 'https://pay.test', 'services.pay.secret_key' => 'sk_test_x', 'services.pay.webhook_secret' => 'whsec_x', 'services.frontend_url' => 'https://inv.test']);
        $this->tenant = Tenant::factory()->create(['plan' => 'basic', 'subscription_ends_at' => null]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
    }

    private function payment(array $over = []): array
    {
        return $over + [
            'id' => 'pi_abc', 'status' => 'succeeded', 'product' => 'zinnvy-inventory', 'amount' => 2900, 'currency' => 'GHS', 'reference' => 'inv-1',
            'metadata' => ['tenant_id' => (string) $this->tenant->id, 'plan' => 'pro', 'cadence' => 'monthly'],
        ];
    }

    private function webhook(array $payment, ?string $signature = null, string $type = 'payment.succeeded')
    {
        $body = json_encode(['id' => 'evt_1', 'type' => $type, 'data' => $payment]);
        $t = time();
        $sig = $signature ?? "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_x');

        return $this->call('POST', '/api/v1/webhooks/pay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ZINNVY_SIGNATURE' => $sig], $body);
    }

    public function test_checkout_creates_a_pay_payment_for_the_price_list_amount(): void
    {
        Http::fake(['pay.test/v1/payment_intents' => Http::response(['id' => 'pi_abc', 'checkout_url' => 'https://pay.test/c/?t=tok'], 201)]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/subscription/checkout', ['plan' => 'pro', 'cadence' => 'yearly'])
            ->assertOk()->assertJsonPath('checkout_url', 'https://pay.test/c/?t=tok');

        Http::assertSent(fn ($r) => $r['amount'] === 29000 && $r['product'] === 'zinnvy-inventory' && $r['metadata']['tenant_id'] === (string) $this->tenant->id
            && $r->hasHeader('Idempotency-Key') && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
        $this->assertSame('pi_abc', $this->tenant->fresh()->settings['pending_pay_id']);
    }

    public function test_only_administrators_can_start_checkout_and_the_client_cannot_set_the_price(): void
    {
        $sales = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']);
        $this->actingAs($sales, 'sanctum')->postJson('/api/v1/subscription/checkout', ['plan' => 'pro', 'cadence' => 'monthly'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/subscription/checkout', ['plan' => 'basic', 'cadence' => 'monthly'])->assertStatus(422);   // free plan: nothing to buy

        Http::fake(['pay.test/v1/payment_intents' => Http::response(['id' => 'pi_x', 'checkout_url' => 'https://pay.test/c/?t=t'], 201)]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/subscription/checkout', ['plan' => 'pro', 'cadence' => 'monthly', 'amount' => 1])->assertOk();
        Http::assertSent(fn ($r) => $r['amount'] === 2900);   // the price list, not the client, sets the amount
    }

    public function test_signed_webhook_grants_the_plan_once(): void
    {
        Http::fake(['pay.test/v1/payment_intents/pi_abc' => Http::response($this->payment())]);

        $this->webhook($this->payment())->assertOk()->assertJsonPath('result', 'applied');
        $this->assertSame('pro', $this->tenant->fresh()->plan);
        $this->assertTrue(now()->addDays(27)->isBefore($this->tenant->fresh()->subscription_ends_at));

        $this->webhook($this->payment())->assertOk()->assertJsonPath('result', 'already_applied');
        $this->assertSame(1, SubscriptionHistory::where('payment_reference', 'pi_abc')->count());
        $h = SubscriptionHistory::where('payment_reference', 'pi_abc')->first();
        $this->assertSame('upgrade', $h->event_type);
        $this->assertSame('zinnvy_pay', $h->gateway);
    }

    public function test_bad_or_stale_signatures_are_rejected(): void
    {
        $this->webhook($this->payment(), 't=1,v1='.str_repeat('a', 64))->assertUnauthorized();
        $this->webhook($this->payment(), 'garbage')->assertUnauthorized();
        $this->assertSame('basic', $this->tenant->fresh()->plan);
    }

    public function test_a_payment_that_does_not_match_the_price_list_grants_nothing(): void
    {
        Http::fake(['pay.test/v1/payment_intents/pi_abc' => Http::response($this->payment(['amount' => 100]))]);
        $this->webhook($this->payment())->assertOk()->assertJsonPath('result', 'ignored');
        $this->assertSame('basic', $this->tenant->fresh()->plan);
    }

    public function test_an_unpaid_payment_grants_nothing(): void
    {
        Http::fake(['pay.test/v1/payment_intents/pi_abc' => Http::response($this->payment(['status' => 'failed']))]);
        $this->webhook($this->payment(['status' => 'failed']))->assertOk()->assertJsonPath('result', 'ignored');
        $this->assertSame('basic', $this->tenant->fresh()->plan);
    }

    public function test_confirm_applies_the_pending_payment_and_extends_from_the_current_end_date(): void
    {
        $end = now()->addDays(10);
        $this->tenant->update(['plan' => 'pro', 'subscription_ends_at' => $end, 'settings' => ['pending_pay_id' => 'pi_abc']]);
        Http::fake(['pay.test/v1/payment_intents/pi_abc' => Http::response($this->payment())]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/subscription/confirm')->assertOk()->assertJsonPath('applied', 'applied');
        $this->assertSame('renewal', SubscriptionHistory::first()->event_type);
        $this->assertTrue(\Carbon\Carbon::parse($this->tenant->fresh()->subscription_ends_at)->greaterThan($end->copy()->addDays(27)));
        $this->assertArrayNotHasKey('pending_pay_id', $this->tenant->fresh()->settings ?? []);
    }

    public function test_confirm_refuses_a_payment_belonging_to_another_workspace(): void
    {
        $this->tenant->update(['settings' => ['pending_pay_id' => 'pi_abc']]);
        Http::fake(['pay.test/v1/payment_intents/pi_abc' => Http::response($this->payment(['metadata' => ['tenant_id' => '999999', 'plan' => 'pro', 'cadence' => 'monthly']]))]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/subscription/confirm')->assertForbidden();
        $this->assertSame('basic', $this->tenant->fresh()->plan);
    }
}
