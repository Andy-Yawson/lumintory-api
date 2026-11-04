<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Dispatch\Settings;
use Illuminate\Console\Command;

class DispatchSetup extends Command
{
    protected $signature = 'dispatch:setup';

    protected $description = 'Creates the internal "Zinnvy Dispatch" workspace that rider sign-ins belong to (safe to repeat)';

    public function handle(): int
    {
        if (Settings::get('platform_tenant_id') && Tenant::find(Settings::get('platform_tenant_id'))) {
            $this->info('Already set up.');

            return self::SUCCESS;
        }
        $tenant = Tenant::create(['name' => 'Zinnvy Dispatch', 'plan' => 'custom', 'is_active' => true]);
        \Illuminate\Support\Facades\DB::table('dispatch_settings')->upsert([['key' => 'platform_tenant_id', 'value' => json_encode($tenant->id), 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
        $this->info("Created workspace #{$tenant->id}.");

        return self::SUCCESS;
    }
}
