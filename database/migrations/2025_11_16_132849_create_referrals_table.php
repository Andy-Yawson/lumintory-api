<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_tenant_id');
            $table->unsignedBigInteger('referred_tenant_id');
            $table->integer('tokens_awarded')->default(0);
            $table->timestamps();

            $table->foreign('referrer_tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade');

            $table->foreign('referred_tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
