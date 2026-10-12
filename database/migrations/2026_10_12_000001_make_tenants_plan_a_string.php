<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans are admin-editable rows in subscription_plans, so tenants.plan must accept any plan slug,
 * not just the original enum('basic','pro','custom') — assigning an admin-created plan failed.
 * Raw SQL because this app (Laravel 10) has no doctrine/dbal for ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tenants MODIFY plan VARCHAR(32) NOT NULL DEFAULT 'basic'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE tenants SET plan = 'custom' WHERE plan NOT IN ('basic','pro','custom')");
            DB::statement("ALTER TABLE tenants MODIFY plan ENUM('basic','pro','custom') NOT NULL DEFAULT 'basic'");
        }
    }
};
