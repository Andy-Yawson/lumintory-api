<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::create([
            'name' => 'Kingstar Aluminium',
            'domain' => 'kingstar.lumintory.app',
            'settings' => ['currency' => 'GHS', 'low_stock_threshold' => 10],
        ]);

        Tenant::create([
            'name' => 'Atrium',
            'domain' => 'atrium.lumintory.app',
            'settings' => ['currency' => 'USD', 'low_stock_threshold' => 10],
        ]);
    }
}
