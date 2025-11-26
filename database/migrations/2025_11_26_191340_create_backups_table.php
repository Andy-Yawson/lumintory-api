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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->default('local');
            $table->string('path')->nullable(); // filled after job runs
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('type')->default('database'); // extend later if needed
            $table->string('status')->default('queued'); // queued | processing | completed | failed
            $table->json('meta')->nullable(); // { initiated_by: user_id }
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
