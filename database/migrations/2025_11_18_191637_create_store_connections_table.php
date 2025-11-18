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
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');

            $table->string('provider'); // shopify|woocommerce|custom
            $table->string('store_name')->nullable();
            $table->string('domain')->nullable(); // e.g. myshop.myshopify.com

            $table->text('access_token')->nullable(); // encrypted
            $table->text('webhook_secret')->nullable(); // encrypted

            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);

            // commission percentage for this store
            $table->decimal('commission_rate', 5, 2)->default(12.00);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_connections');
    }
};
