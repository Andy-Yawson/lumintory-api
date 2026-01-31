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
        Schema::table('product_variations', function (Blueprint $table) {
            $table->decimal('discount_price', 15, 2)->nullable()->after('unit_price');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0)->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn('discount_price');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
