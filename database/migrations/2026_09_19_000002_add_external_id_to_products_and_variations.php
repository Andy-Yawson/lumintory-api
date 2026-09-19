<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Custom API integration (IntegrationProductController,
 * IntegrationOrderController) has always assumed a `products.external_id`
 * column existed to map an external system's product id onto a Zinnvy
 * Product for idempotent upserts — it never did. Every sync call carrying
 * an external_id, and the product-listing endpoint's bogus `variations`
 * column select, has been failing with a SQL error since this integration
 * was built. Adds it here, plus the equivalent on product_variations so a
 * real e-commerce platform's product variants can be synced too, not just
 * flat products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('sku');
            $table->unique(['tenant_id', 'external_id']);
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('sku');
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn('external_id');
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
