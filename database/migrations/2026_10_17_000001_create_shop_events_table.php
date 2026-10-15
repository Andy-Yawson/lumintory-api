<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous shop visit events for Shop Insights. No names, phone numbers or IP addresses:
 * visitor_id is a random id kept in the visitor's browser, ip_hash is a one-way hash that
 * changes daily (used only against abuse), so it can't be traced back to a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                  // view, product_view, add_to_cart, checkout, share, chat, search
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('visitor_id', 40);
            $table->string('source', 20)->default('direct'); // whatsapp, instagram, facebook, tiktok, google, marketplace, direct, other
            $table->string('campaign', 80)->nullable();
            $table->string('device', 10)->nullable();    // mobile, tablet, desktop
            $table->string('search', 80)->nullable();
            $table->unsignedSmallInteger('results')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'type', 'created_at']);
        });

        Schema::table('shops', function (Blueprint $table) {
            // The owner's own tracking, loaded on their shop pages only.
            $table->string('meta_pixel_id', 30)->nullable()->after('momo_merchant_id');
            $table->string('ga_measurement_id', 30)->nullable()->after('meta_pixel_id');
            $table->string('tiktok_pixel_id', 40)->nullable()->after('ga_measurement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_events');
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['meta_pixel_id', 'ga_measurement_id', 'tiktok_pixel_id']));
    }
};
