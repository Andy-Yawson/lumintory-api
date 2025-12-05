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
        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->integer('reorder_point')->unsigned()->nullable();
            $table->integer('safety_stock')->unsigned()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->dropColumn('reorder_point');
            $table->dropColumn('safety_stock');
        });
    }
};
