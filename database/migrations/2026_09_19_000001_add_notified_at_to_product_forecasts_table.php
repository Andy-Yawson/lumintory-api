<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decouples "we recorded this forecast" from "we emailed someone about it".
 * Without this, the only signal available for throttling notifications was
 * forecast-record creation itself, which fires on every risk-level change —
 * including a product oscillating warning/critical/ok run to run as the
 * recency-weighted demand average gets recalculated on real, noisy sales
 * data. That's what produced the "sends too frequently" behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('forecasted_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
