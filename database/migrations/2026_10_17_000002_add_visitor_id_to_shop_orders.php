<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The anonymous Shop Insights visitor who placed the order, so the funnel connects visits to orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->string('visitor_id', 40)->nullable()->after('source'));
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->dropColumn('visitor_id'));
    }
};
