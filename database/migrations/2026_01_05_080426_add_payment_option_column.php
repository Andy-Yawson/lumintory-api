<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_method')->default('Cash')->after('total_amount');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->string('refund_method')->default('Cash')->after('refund_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn('refund_method');
        });
    }
};
