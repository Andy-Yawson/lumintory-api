<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales imported from an old spreadsheet are history: the stock they sold was already gone
 * before the shop's opening stock was entered. stock_deducted = false marks those rows so
 * deleting one later doesn't "give back" stock that was never taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('stock_deducted')->default(true)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('stock_deducted');
        });
    }
};
