<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short product videos. Uploaded in chunks through the API, then converted (ffmpeg) to a small
 * H.264 MP4 that plays everywhere, plus a poster frame. Original files are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('uploading'); // uploading, processing, ready, failed
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size');                  // bytes expected for the upload
            $table->unsignedInteger('chunks_received')->default(0);
            $table->string('source_path')->nullable();           // R2, deleted after conversion
            $table->string('path')->nullable();                  // R2 mp4
            $table->string('poster_path')->nullable();           // R2 jpg
            $table->decimal('duration', 6, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->boolean('trimmed')->default(false);
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_videos');
    }
};
