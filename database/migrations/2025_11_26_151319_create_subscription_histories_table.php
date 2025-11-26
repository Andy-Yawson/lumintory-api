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
        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');

            $table->string('from_plan')->nullable();
            $table->string('to_plan');

            $table->enum('event_type', [
                'signup',
                'upgrade',
                'downgrade',
                'renewal',
                'cancel',
                'reactivate',
            ]);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('gateway')->nullable();
            $table->timestamp('effective_at');
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_histories');
    }
};
