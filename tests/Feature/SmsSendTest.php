<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SmsCredit;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Credits are charged for exactly the messages that get queued: never more, never for nothing. */
class SmsSendTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tenant = Tenant::factory()->create(['plan' => 'custom']);
        SmsCredit::create(['tenant_id' => $this->tenant->id, 'credits' => 20]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']));
        foreach ([['Adwoa', '0245550182'], ['Kojo', '+233 20 772 9011'], ['No phone', null], ['Dup of Adwoa', '233245550182']] as [$n, $p]) {
            Customer::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => $n, 'phone' => $p]);
        }
    }

    private function credits(): int
    {
        return (int) SmsCredit::where('tenant_id', $this->tenant->id)->value('credits');
    }

    public function test_message_all_charges_and_queues_each_unique_number_once(): void
    {
        $this->postJson('/api/v1/sms/send-bulk', ['message' => 'Hello', 'mode' => 'all_filtered', 'filters' => []])
            ->assertOk()->assertJsonPath('recipients', 2);

        $this->assertSame(18, $this->credits());
        $this->assertSame(2, SmsLog::count());
        $this->assertEqualsCanonicalizing(['233245550182', '233207729011'], SmsLog::pluck('recipient')->all());
    }

    public function test_selected_numbers_are_normalised_and_deduplicated_before_charging(): void
    {
        $this->postJson('/api/v1/sms/send-bulk', ['message' => 'Hi', 'mode' => 'selected', 'recipients' => ['0245550182', '233245550182', '0207729011']])
            ->assertOk();

        $this->assertSame(18, $this->credits());
        $this->assertSame(2, SmsLog::count());
    }

    public function test_long_messages_cost_one_credit_per_153_characters_after_the_first_160(): void
    {
        $this->postJson('/api/v1/sms/send-bulk', ['message' => str_repeat('a', 161), 'mode' => 'selected', 'recipients' => ['0245550182']])
            ->assertOk()->assertJsonPath('charged_sms', 2);
        $this->assertSame(18, $this->credits());
    }

    public function test_not_enough_credits_charges_nothing_and_sends_nothing(): void
    {
        SmsCredit::where('tenant_id', $this->tenant->id)->update(['credits' => 1]);

        $this->postJson('/api/v1/sms/send-bulk', ['message' => 'Hello', 'mode' => 'all_filtered'])->assertStatus(422);

        $this->assertSame(1, $this->credits());
        $this->assertSame(0, SmsLog::count());
    }

    public function test_single_send_charges_once(): void
    {
        $this->postJson('/api/v1/sms/send', ['message' => 'Hello', 'recipients' => ['0245550182']])->assertOk();
        $this->assertSame(19, $this->credits());
        $this->assertSame(1, SmsLog::count());
    }
}
