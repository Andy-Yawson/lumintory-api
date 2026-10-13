<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where an order came from (utm_source / medium / campaign, referrer), so shops can see which campaigns sell. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->json('source')->nullable()->after('note'));
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
