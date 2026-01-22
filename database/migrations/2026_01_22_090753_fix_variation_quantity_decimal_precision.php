<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('product_variations', function (Blueprint $table) {
            // '15' is the total digits, '3' is the number of digits after the decimal point.
            // change() ensures we modify the existing column.
            $table->decimal('quantity', 15, 3)->default(0)->change();
        });

        // Double check the products table as well to be safe
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 15, 3)->default(0)->change();
        });
    }

    public function down()
    {
        Schema::table('product_variations', function (Blueprint $table) {
            // Fallback to integer if you absolutely must, 
            // but usually, you'd keep it as decimal once fixed.
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            // Fallback to integer if you absolutely must, 
            // but usually, you'd keep it as decimal once fixed.
            $table->integer('quantity')->default(0)->change();
        });
    }
};
