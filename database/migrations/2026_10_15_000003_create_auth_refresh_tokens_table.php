<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh tokens: one "family" per signed-in device. Each refresh swaps the pair for a new one
 * (rotation); presenting an already-used refresh token outside a short grace window means it was
 * copied, so the whole family (that device's session) is revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family')->index();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('access_token_id')->nullable(); // personal_access_tokens.id issued with it
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('family_started_at');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_refresh_tokens');
    }
};
