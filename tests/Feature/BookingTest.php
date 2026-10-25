<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopService;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Shop $shop;
    private ShopService $install;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // A Monday at 08:00, so "tomorrow" is a working Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00'));
        $this->tenant = Tenant::factory()->create(['name' => 'Hair by Esi', 'plan' => 'pro', 'subscription_ends_at' => now()->addYear()]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
        $this->shop = Shop::create(['tenant_id' => $this->tenant->id, 'slug' => 'esi', 'enabled' => true, 'pickup' => true, 'notify_phone' => '0201112222']);
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/bookings/settings', [
            'bookings_enabled' => true, 'notice_hours' => 12, 'max_days' => 14, 'buffer_minutes' => 30,
            'hours' => ['mon' => [['09:00', '17:00']], 'tue' => [['09:00', '13:00']], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
        ])->assertOk();
        $id = $this->postJson('/api/v1/bookings/services', ['name' => 'Wig installation', 'price' => 150, 'price_from' => true, 'duration_minutes' => 120, 'location' => 'both'])->assertCreated()->json('id');
        $this->install = ShopService::withoutGlobalScopes()->find($id);
        $this->app['auth']->forgetGuards();
    }

    private function times(string $date): array
    {
        return $this->getJson("/api/v1/public/shops/esi/services/{$this->install->id}/availability?date={$date}")->assertOk()->json('times');
    }

    private function book(string $at, array $extra = [])
    {
        return $this->postJson('/api/v1/public/shops/esi/bookings', $extra + [
            'service_id' => $this->install->id, 'starts_at' => $at, 'location' => 'shop', 'name' => 'Akua', 'phone' => '0244556677', 'consent' => true,
        ]);
    }

    public function test_slots_follow_hours_notice_duration_and_closed_days(): void
    {
        $this->assertSame([], $this->times('2026-11-02'), 'today is inside the 12h notice');
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00'], $this->times('2026-11-03'), 'Tue 9–13, 2h service: last start 11:00');
        $this->assertSame([], $this->times('2026-11-04'), 'closed on Wednesdays');
        $this->assertSame([], $this->times('2026-11-30'), 'beyond the 14-day window');
        $dates = $this->getJson("/api/v1/public/shops/esi/services/{$this->install->id}/availability")->json('dates');
        $this->assertSame(['2026-11-03', '2026-11-09', '2026-11-10', '2026-11-16'], $dates);
    }

    public function test_a_booking_takes_its_slot_plus_buffer_and_notifies_both_sides(): void
    {
        $this->book('2026-11-03 09:00')->assertCreated()->assertJsonPath('number', 1001)->assertJsonPath('status', 'requested');
        // 09:00–11:00 + 30 min buffer: nothing can start before 11:30 (and 11:30 + 2h runs past 13:00).
        $this->assertSame([], $this->times('2026-11-03'));
        $this->assertTrue(SmsLog::where('recipient', '233201112222')->where('message', 'like', 'Booking request B-1001%')->exists(), 'owner SMS');
        $this->assertTrue(SmsLog::where('recipient', '233244556677')->where('message', 'like', '%got your booking request%')->exists(), 'customer SMS');
        $this->book('2026-11-03 10:00', ['phone' => '0277000000'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    public function test_home_visits_need_an_address_and_off_hours_are_refused(): void
    {
        $this->book('2026-11-09 10:00', ['location' => 'home'])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->book('2026-11-09 10:00', ['location' => 'home', 'address' => 'East Legon, near the mall'])->assertCreated();
        $this->book('2026-11-09 18:00', ['phone' => '0277000001'])->assertStatus(422);
        $this->book('2026-11-03 09:00', ['consent' => false])->assertStatus(422)->assertJsonValidationErrors('consent');
    }

    public function test_owner_confirms_declines_and_reschedules_with_texts(): void
    {
        $this->book('2026-11-03 09:00')->assertCreated();
        $b = Booking::withoutGlobalScopes()->firstOrFail();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/bookings?view=requested')->assertJsonPath('counts.requested', 1)->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/bookings/{$b->id}", ['status' => 'confirmed'])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertTrue(SmsLog::where('recipient', '233244556677')->where('message', 'like', 'Confirmed!%')->exists());

        $this->postJson("/api/v1/bookings/{$b->id}/reschedule", ['starts_at' => '2026-11-09 14:00', 'note' => 'Running late that day'])->assertOk();
        $this->assertSame('2026-11-09 14:00', $b->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertTrue(SmsLog::where('message', 'like', '%moved your Wig installation booking to Mon 9 Nov, 2:00pm%')->exists());

        $this->patchJson("/api/v1/bookings/{$b->id}", ['status' => 'declined', 'note' => 'Fully booked'])->assertOk();
        $this->assertContains('11:00', $this->times('2026-11-03'), 'declined bookings free their slot');
    }

    public function test_reminders_go_out_once_the_day_before(): void
    {
        $this->book('2026-11-03 09:00')->assertCreated();
        Booking::withoutGlobalScopes()->first()->update(['status' => 'confirmed']);
        $this->artisan('bookings:remind')->expectsOutputToContain('Sent 1 reminder(s).');
        $this->artisan('bookings:remind')->expectsOutputToContain('Sent 0 reminder(s).');
    }

    public function test_bookings_are_off_until_enabled_and_pro_only(): void
    {
        $this->shop->update(['bookings_enabled' => false]);
        $this->getJson('/api/v1/public/shops/esi/services')->assertNotFound();

        $basic = Tenant::factory()->create(['plan' => 'basic']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $basic->id, 'role' => 'Administrator']));
        $this->putJson('/api/v1/bookings/settings', ['bookings_enabled' => true])->assertForbidden();
    }

    public function test_owners_only_see_their_own_bookings(): void
    {
        $this->book('2026-11-03 09:00')->assertCreated();
        $id = Booking::withoutGlobalScopes()->first()->id;
        $other = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $other->id, 'role' => 'Administrator']));
        $this->patchJson("/api/v1/bookings/{$id}", ['status' => 'confirmed'])->assertNotFound();
        $this->getJson('/api/v1/bookings')->assertJsonCount(0, 'data');
    }

    public function test_linked_services_show_on_the_product_page(): void
    {
        $wig = new \App\Models\Product(['name' => 'Body wave 20"', 'quantity' => 3, 'unit_price' => 900]);
        $wig->tenant_id = $this->tenant->id;
        $wig->shop_visible = true;
        $wig->save();
        $this->install->update(['product_ids' => [$wig->id]]);
        $this->getJson("/api/v1/public/shops/esi/products/{$wig->id}")->assertOk()->assertJsonPath('services.0.name', 'Wig installation');
        $this->getJson('/api/v1/public/shops/esi')->assertJsonPath('takes_bookings', true);
    }
}
