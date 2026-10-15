<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('inventory:forecast --window=30')->everyThreeHours();
        $schedule->command('app:credit-remainder')->hourly();
        $schedule->command('stores:sync')->hourly();
        // Expired sign-in tokens: access tokens (Sanctum) and refresh tokens.
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        // Shop Insights: Monday 8am (Ghana time = UTC) digest, and drop visit events after 13 months.
        $schedule->command('shop:weekly-digest')->weeklyOn(1, '08:00');
        $schedule->call(fn () => \App\Models\ShopEvent::where('created_at', '<', now()->subMonths(13))->delete())->daily()->name('prune-shop-events');
        $schedule->call(fn () => \App\Services\AuthSessions::prune())->daily()->name('prune-refresh-tokens');
    }


    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
