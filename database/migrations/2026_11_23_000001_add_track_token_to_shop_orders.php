<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A customer's private tracking link from the moment they order (before any rider is requested). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $t) {
            $t->string('track_token', 40)->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $t) {
            $t->dropUnique(['track_token']);
            $t->dropColumn('track_token');
        });
    }
};
