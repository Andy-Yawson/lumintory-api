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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('recipient');
            $table->text('message');
            $table->string('provider_message_id')->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->integer('segments')->default(1);
            $table->decimal('cost', 10, 4)->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
