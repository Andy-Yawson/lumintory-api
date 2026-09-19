<?php

namespace Tests\Feature;

use App\Jobs\SendQueuedMail;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

/**
 * persistAndNotify() drives the weighted-demand/reorder-point math (see
 * InventoryForecastService) with real sales history, which is realistic
 * but awkward to stage for a specific risk sequence in a test. These tests
 * invoke it directly via reflection to exercise exactly the notification
 * cooldown that was fixed, independent of the demand-forecasting math.
 */
class InventoryForecastNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function notify(InventoryForecastService $service, Product $product, string $risk): void
    {
        $method = new ReflectionMethod($service, 'persistAndNotify');
        $method->setAccessible(true);
        $method->invoke($service, $product, $risk, 10.0, 5.0, 2.0, $product->quantity, 1.0, null);
    }

    public function test_a_first_time_critical_forecast_notifies_admins(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $this->notify(new InventoryForecastService, $product, 'critical');

        Bus::assertDispatchedTimes(SendQueuedMail::class, 1);
    }

    public function test_a_product_flapping_between_warning_and_critical_is_not_re_notified_within_24_hours(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $service = new InventoryForecastService;

        // This is the exact scenario that made production notifications
        // "send too frequently": the forecast is recomputed from real,
        // noisy daily-sales data every 3 hours, so a borderline item can
        // flip risk level run to run even though nothing meaningfully
        // changed.
        $this->notify($service, $product, 'critical'); // notifies (first time)
        $this->notify($service, $product, 'warning');  // de-escalation, no notify
        $this->notify($service, $product, 'critical'); // back to critical, but <24h since last critical notification

        Bus::assertDispatchedTimes(SendQueuedMail::class, 1);
    }

    public function test_escalating_from_warning_to_critical_notifies_immediately_even_inside_the_cooldown(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $service = new InventoryForecastService;

        $this->notify($service, $product, 'warning');  // notifies (first time)
        $this->notify($service, $product, 'critical'); // genuine escalation — should notify immediately

        Bus::assertDispatchedTimes(SendQueuedMail::class, 2);
    }

    public function test_a_stable_warning_does_not_notify_again_within_24_hours(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $service = new InventoryForecastService;

        $this->notify($service, $product, 'warning');
        $this->notify($service, $product, 'warning');
        $this->notify($service, $product, 'warning');

        Bus::assertDispatchedTimes(SendQueuedMail::class, 1);
    }
}
