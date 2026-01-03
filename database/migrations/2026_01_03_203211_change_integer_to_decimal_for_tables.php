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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('quantity', 12, 1)->change();
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 1)->change();
        });

        // Also update the products table stock quantity if you haven't already
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }
};
