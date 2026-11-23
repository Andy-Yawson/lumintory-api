<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One id per checkout, so "sales" and "average order" count baskets, not cart lines. Older rows stay null (one line each). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->string('basket_id', 36)->nullable()->after('tenant_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->dropIndex(['basket_id']);
            $t->dropColumn('basket_id');
        });
    }
};
