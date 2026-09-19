<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntegrationApiKey>
 */
class IntegrationApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Test integration',
            'public_key' => 'int_'.Str::random(40),
            'secret' => Str::random(60),
            'scopes' => ['products:read', 'products:write', 'orders:write'],
            'is_active' => true,
        ];
    }
}
