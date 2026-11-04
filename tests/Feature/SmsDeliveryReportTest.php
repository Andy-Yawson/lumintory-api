<?php

namespace Tests\Feature;

use App\Models\SmsLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsDeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $status = 'sent'): SmsLog
    {
        $tenant = Tenant::factory()->create();

        return SmsLog::create(['tenant_id' => $tenant->id, 'recipient' => '233200000000', 'message' => 'Hi', 'provider_message_id' => 'm1', 'status' => $status, 'segments' => 1]);
    }

    public function test_provider_states_are_translated_into_ours_never_stored_raw(): void
    {
        $log = $this->log();
        foreach (['buffered', 'unknown', 'ACCEPTD', 'weird-new-state'] as $state) {
            $this->postJson('/api/sms/callback', ['message_id' => 'm1', 'status' => $state])->assertNoContent();
            $this->assertSame('sent', $log->fresh()->status, "{$state} means sent but unconfirmed");
        }
        $this->postJson('/api/sms/callback', ['message_id' => 'm1', 'status' => 'DELIVRD'])->assertNoContent();
        $this->assertSame('delivered', $log->fresh()->status);
        $this->assertCount(5, $log->fresh()->provider_response['delivery_reports'], 'every raw report is kept for support');
    }

    public function test_a_late_report_never_moves_a_message_backwards(): void
    {
        $log = $this->log('delivered');
        $this->postJson('/api/sms/callback', ['message_id' => 'm1', 'status' => 'buffered'])->assertNoContent();
        $this->assertSame('delivered', $log->fresh()->status);

        $failed = $this->log('sent');
        $failed->update(['provider_message_id' => 'm2']);
        $this->postJson('/api/sms/callback', ['message_id' => 'm2', 'status' => 'undelivered'])->assertNoContent();
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_an_unknown_message_or_missing_id_is_ignored_quietly(): void
    {
        $this->postJson('/api/sms/callback', ['message_id' => 'nope', 'status' => 'delivered'])->assertNoContent();
        $this->postJson('/api/sms/callback', ['status' => 'delivered'])->assertNoContent();
    }
}
