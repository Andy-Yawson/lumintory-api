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
        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_connection_id')->constrained('store_connections')->onDelete('cascade');

            $table->string('shop_product_id'); // provider product ID
            $table->string('shop_variant_id')->nullable(); // provider variant ID
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('set null');
            $table->string('sku')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['store_connection_id', 'shop_product_id', 'shop_variant_id'], 'shop_prod_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_products');
    }
};
