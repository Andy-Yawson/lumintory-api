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
        Schema::create('product_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');

            // input window used for this forecast
            $table->integer('window_days')->default(30);

            // core forecast numbers
            $table->decimal('avg_daily_sales', 10, 2)->nullable();
            $table->decimal('predicted_days_to_stockout', 10, 2)->nullable();
            $table->integer('current_quantity')->nullable();

            // risk band for UI
            $table->enum('stock_risk_level', ['ok', 'warning', 'critical'])->default('ok');

            // when this forecast was computed
            $table->timestamp('forecasted_at')->useCurrent();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'stock_risk_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_forecasts');
    }
};
