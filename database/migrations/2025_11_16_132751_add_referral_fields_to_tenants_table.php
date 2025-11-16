<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('name');
            $table->unsignedBigInteger('referred_by_tenant_id')->nullable()->after('referral_code');

            $table->foreign('referred_by_tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropForeign(['referred_by_tenant_id']);
                $table->dropColumn(['referral_code', 'referred_by_tenant_id']);
            });
        });
    }
};
