<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookable services on the online shop (wig installation, fittings, repairs…). One calendar per
 * shop: the owner sets weekly hours, the shop offers free slots, customers request a time and the
 * owner confirms. No online payment; a deposit can be paid to the shop's Mobile Money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('price_from')->default(false);       // "from GH₵ 150"
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('location', 10)->default('shop');     // shop | home | both
            $table->string('travel_note', 200)->nullable();      // "Accra only, transport extra"
            $table->string('image_path')->nullable();
            $table->json('product_ids')->nullable();             // offered on these products' pages
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('shop_services')->nullOnDelete();
            $table->unsignedInteger('number');                   // B-1001…, per workspace
            $table->string('status', 12)->default('requested');  // requested, confirmed, completed, cancelled, declined, no_show
            $table->string('service_name', 120);
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('location', 10)->default('shop');
            $table->string('address', 300)->nullable();
            $table->string('for_product', 160)->nullable();      // "Bought: Brazilian body wave 20\""
            $table->string('customer_name', 80);
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('owner_note', 300)->nullable();       // reason when declining / rescheduling
            $table->json('source')->nullable();
            $table->string('visitor_id', 40)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['shop_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('bookings_enabled')->default(false)->after('tiktok_pixel_id');
            $table->json('booking_hours')->nullable()->after('bookings_enabled');     // {"mon":[["09:00","18:00"]], …}
            $table->json('booking_blocked_dates')->nullable()->after('booking_hours'); // ["2026-12-25", …]
            $table->unsignedSmallInteger('booking_notice_hours')->default(12)->after('booking_blocked_dates');
            $table->unsignedSmallInteger('booking_max_days')->default(30)->after('booking_notice_hours');
            $table->unsignedSmallInteger('booking_buffer_minutes')->default(0)->after('booking_max_days');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('shop_services');
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['bookings_enabled', 'booking_hours', 'booking_blocked_dates', 'booking_notice_hours', 'booking_max_days', 'booking_buffer_minutes']));
    }
};
