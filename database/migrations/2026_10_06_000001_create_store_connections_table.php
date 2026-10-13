<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per connected online store (Shopify / WooCommerce). Credentials are
 * stored encrypted. `sales.external_order_id` makes order ingestion idempotent,
 * because stores re-deliver webhooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('store_url');
            $table->string('status', 24)->default('pending'); // pending | active | disconnected | error
            $table->string('state_token', 64)->nullable()->index();
            $table->text('credentials')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'store_url']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('external_order_id')->nullable()->after('notes');
            $table->index(['tenant_id', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'external_order_id']);
            $table->dropColumn('external_order_id');
        });

        Schema::dropIfExists('store_connections');
    }
};
