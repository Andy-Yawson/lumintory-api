<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zinnvy's own riders. Platform-level (no tenant): they serve every shop.
        Schema::create('dispatch_riders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // their Zinnvy sign-in
            $t->string('name', 80);
            $t->string('phone', 20);
            $t->string('email')->unique();
            $t->string('status', 20)->default('invited');       // invited | pending_review | active | suspended | rejected
            $t->string('vehicle', 20)->nullable();               // bike | motorbike | car | van
            $t->string('plate', 20)->nullable();
            $t->string('id_number', 40)->nullable();             // Ghana Card
            $t->string('id_photo_path')->nullable();             // private: shown only to staff
            $t->string('selfie_path')->nullable();
            $t->string('guarantor_name', 80)->nullable();
            $t->string('guarantor_phone', 20)->nullable();
            $t->string('review_note')->nullable();
            $t->boolean('online')->default(false);
            $t->timestamp('last_seen_at')->nullable();
            $t->decimal('last_lat', 9, 6)->nullable();
            $t->decimal('last_lng', 9, 6)->nullable();
            $t->unsignedInteger('deliveries')->default(0);
            $t->timestamps();
        });

        Schema::create('dispatch_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('code', 12)->unique();                    // what people say on the phone: D-4821
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();   // an order can be re-requested after a failed or cancelled job
            $t->foreignId('rider_id')->nullable()->constrained('dispatch_riders')->nullOnDelete();
            $t->string('status', 20)->default('searching');      // searching | assigned | picked_up | delivered | failed | cancelled
            $t->string('pickup_name', 120);
            $t->string('pickup_address', 300);
            $t->string('pickup_phone', 20)->nullable();
            $t->decimal('pickup_lat', 9, 6)->nullable();
            $t->decimal('pickup_lng', 9, 6)->nullable();
            $t->string('dropoff_name', 80);
            $t->string('dropoff_phone', 20);
            $t->string('dropoff_address', 300);
            $t->string('dropoff_landmark', 200)->nullable();
            $t->decimal('dropoff_lat', 9, 6)->nullable();
            $t->decimal('dropoff_lng', 9, 6)->nullable();
            $t->decimal('distance_km', 6, 2)->nullable();
            // Money, in pesewas, fixed when the job is requested so later rate changes never touch it.
            $t->unsignedBigInteger('goods_amount');
            $t->unsignedBigInteger('delivery_fee');
            $t->unsignedBigInteger('rider_share');
            $t->unsignedBigInteger('goods_commission')->default(0);
            $t->json('rates')->nullable();                       // the percentages used, for the record
            $t->string('handover_code_hash')->nullable();
            $t->string('pay_status', 12)->default('unpaid');     // unpaid | pending | paid
            $t->string('pay_intent_id')->nullable()->index();
            $t->string('pay_checkout_url', 300)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->unsignedTinyInteger('offer_rounds')->default(0);
            $t->boolean('trip_fee_due')->default(false);          // a failed trip the rider is still owed for
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('picked_up_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['rider_id', 'status']);
        });

        Schema::create('dispatch_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_id')->constrained('dispatch_jobs')->cascadeOnDelete();
            $t->foreignId('rider_id')->constrained('dispatch_riders')->cascadeOnDelete();
            $t->string('status', 12)->default('offered');        // offered | accepted | declined | expired | withdrawn
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['job_id', 'rider_id']);
        });

        // Admin-editable rules: commission, fee bands, timings. One row per key.
        Schema::create('dispatch_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value');
            $t->timestamps();
        });

        Schema::table('shops', function (Blueprint $t) {
            $t->string('pickup_address', 300)->nullable();       // where riders collect orders
            $t->string('pickup_phone', 20)->nullable();
            $t->decimal('pickup_lat', 9, 6)->nullable();
            $t->decimal('pickup_lng', 9, 6)->nullable();
            $t->boolean('rider_delivery')->default(false);       // offer Zinnvy riders at checkout
        });

        Schema::table('shop_orders', function (Blueprint $t) {
            $t->string('payment_method', 20)->default('later');  // later (as before) | momo_on_delivery
            $t->unsignedBigInteger('delivery_fee')->default(0);  // pesewas
            $t->string('landmark', 200)->nullable();
            $t->decimal('delivery_lat', 9, 6)->nullable();
            $t->decimal('delivery_lng', 9, 6)->nullable();
            $t->decimal('distance_km', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->dropColumn(['payment_method', 'delivery_fee', 'landmark', 'delivery_lat', 'delivery_lng', 'distance_km']));
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['pickup_address', 'pickup_phone', 'pickup_lat', 'pickup_lng', 'rider_delivery']));
        foreach (['dispatch_settings', 'dispatch_offers', 'dispatch_jobs', 'dispatch_riders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
