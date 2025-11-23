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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()->nullable();
            $table->unsignedBigInteger('user_id')->index()->nullable();

            $table->string('event')->nullable();          // e.g. products.store
            $table->string('method', 10)->nullable();     // GET/POST/PUT...
            $table->string('route')->nullable();          // api/products
            $table->string('controller')->nullable();     // ProductController@store

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->decimal('duration_ms', 10, 2)->nullable();

            $table->json('request')->nullable();
            $table->json('response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
