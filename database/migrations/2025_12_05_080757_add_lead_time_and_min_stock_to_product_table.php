<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->default(7)->after('quantity')->comment('Time in days required to receive a new order.');
            $table->unsignedInteger('min_stock_threshold')->default(10)->after('lead_time_days')->comment('The fixed quantity considered too low, regardless of sales rate.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('lead_time_days');
            $table->dropColumn('min_stock_threshold');
        });
    }
};
