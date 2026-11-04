<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_jobs', function (Blueprint $t) {
            $t->unsignedTinyInteger('rating')->nullable();         // 1-5, from the customer, once
            $t->string('rating_comment', 300)->nullable();
            $t->timestamp('rated_at')->nullable();
            $t->index(['rider_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_jobs', function (Blueprint $t) {
            $t->dropIndex(['rider_id', 'rating']);
            $t->dropColumn(['rating', 'rating_comment', 'rated_at']);
        });
    }
};
