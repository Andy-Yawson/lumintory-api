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
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('sale_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->json('variation')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity');
            $table->decimal('refund_amount', 10, 2);
            $table->text('reason');
            $table->date('return_date')->useCurrent();
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->index(['tenant_id', 'return_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
