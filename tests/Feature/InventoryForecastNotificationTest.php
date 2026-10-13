<?php

namespace Tests\Feature;

use App\Jobs\SendQueuedMail;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Stock-forecast alerts are shown in the dashboard (notification bell + Stock forecast page via
 * /inventory-insights), not emailed: the emails arrived several times a day and were unreliable.
 */
class InventoryForecastNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function persist(Product $product, string $risk): void
    {
        $method = new ReflectionMethod(InventoryForecastService::class, 'persistAndNotify');
        $method->setAccessible(true);
        $method->invoke(new InventoryForecastService, $product, $risk, 10.0, 5.0, 2.0, $product->quantity, 1.0, null);
    }

    public function test_an_at_risk_forecast_sends_no_email_but_shows_up_for_the_dashboard(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Shea Butter']);

        $this->persist($product, 'critical');

        Bus::assertNotDispatched(SendQueuedMail::class);
        $this->assertSame(1, ProductForecast::where('product_id', $product->id)->count());

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/inventory-insights')
            ->assertOk()
            ->assertJsonPath('data.0.product.name', 'Shea Butter')
            ->assertJsonPath('data.0.stock_risk_level', 'critical');
    }

    public function test_repeated_runs_never_email(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['warning', 'critical', 'warning', 'critical'] as $risk) {
            $this->persist($product, $risk);
        }

        Bus::assertNotDispatched(SendQueuedMail::class);
    }
}
