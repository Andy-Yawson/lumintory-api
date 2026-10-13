<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional Mobile Money details customers can pay to once the shop confirms their order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('momo_network', 20)->nullable()->after('delivery_note');   // MTN, Telecel, AirtelTigo
            $table->string('momo_number', 20)->nullable()->after('momo_network');
            $table->string('momo_name', 80)->nullable()->after('momo_number');        // registered account name
            $table->string('momo_merchant_id', 40)->nullable()->after('momo_name');  // MoMoPay / merchant code
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['momo_network', 'momo_number', 'momo_name', 'momo_merchant_id']));
    }
};
