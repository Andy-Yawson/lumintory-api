<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();         // slug: basic, pro, custom
            $table->string('label');                  // display: Basic, Pro, Custom
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->string('currency', 10)->default('GHS');
            $table->json('limits');                   // mirrors plan_limits.php keys
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed from existing config so nothing breaks
        $plans = [
            [
                'name'          => 'basic',
                'label'         => 'Basic',
                'price_monthly' => 0,
                'price_yearly'  => 0,
                'currency'      => 'GHS',
                'sort_order'    => 1,
                'limits'        => json_encode(config('plan_limits.basic', [])),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'pro',
                'label'         => 'Pro',
                'price_monthly' => 29,
                'price_yearly'  => 290,
                'currency'      => 'GHS',
                'sort_order'    => 2,
                'limits'        => json_encode(config('plan_limits.pro', [])),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'custom',
                'label'         => 'Custom',
                'price_monthly' => null,
                'price_yearly'  => null,
                'currency'      => 'GHS',
                'sort_order'    => 3,
                'limits'        => json_encode(config('plan_limits.custom', [])),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ];

        DB::table('subscription_plans')->insert($plans);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
