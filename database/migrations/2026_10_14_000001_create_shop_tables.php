<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online shop (shop.zinnvy.com): one shop per workspace, products opt in one by one,
 * and visitors place order requests (no payment yet) that the owner follows up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('public_id', 32)->unique();      // 93923-33932-3939-2393: permanent, unguessable
            $table->string('slug', 40)->nullable()->unique(); // earles-closet: readable, can change
            $table->boolean('enabled')->default(false);
            $table->boolean('in_marketplace')->default(true);
            $table->string('tagline', 120)->nullable();
            $table->text('about')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('notify_phone', 20)->nullable();   // SMS on every new order
            $table->string('hours', 120)->nullable();
            $table->boolean('pickup')->default(true);
            $table->boolean('delivery')->default(false);
            $table->string('delivery_note', 200)->nullable();
            $table->json('blocked_phones')->nullable();
            $table->timestamp('unlisted_at')->nullable();      // Zinnvy moderation
            $table->string('unlisted_reason', 200)->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('shop_visible')->default(false)->after('min_stock_threshold');
        });

        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');                  // #1001, per workspace
            $table->string('status', 20)->default('new');       // new, contacted, confirmed, completed, cancelled
            $table->string('customer_name', 80);
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->string('fulfilment', 10);                    // pickup | delivery
            $table->string('address', 300)->nullable();
            $table->string('note', 500)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->unsignedInteger('item_count');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sale_ids')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['shop_id', 'customer_phone', 'created_at']);
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->string('name');
            $table->string('variation_name')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_items');
        Schema::dropIfExists('shop_orders');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('shop_visible'));
        Schema::dropIfExists('shops');
    }
};
