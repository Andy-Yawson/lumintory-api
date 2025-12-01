<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\InventoryForecastService;
use Illuminate\Console\Command;

class RunInventoryForecasts extends Command
{
    protected $signature = 'inventory:forecast {--window=30}';
    protected $description = 'Run inventory forecast for all tenants';

    public function handle(InventoryForecastService $service): int
    {
        $windowDays = (int) $this->option('window');

        Tenant::where('is_active', true)->where('plan', 'pro')->chunk(100, function ($tenants) use ($service, $windowDays) {
            foreach ($tenants as $tenant) {
                $this->info("Forecasting for tenant #{$tenant->id} ({$tenant->name})...");
                $service->forecastForTenant($tenant->id, $windowDays);
            }
        });

        $this->info('Forecasts completed.');
        return Command::SUCCESS;
    }
}
