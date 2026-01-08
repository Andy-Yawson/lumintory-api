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
            $table->foreignId('product_variation_id')->nullable()->constrained()->onDelete('cascade')->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->dropForeign(['product_variation_id']);
            $table->dropIndex(['product_variation_id']);
            $table->dropColumn('product_variation_id');
        });
    }
};
