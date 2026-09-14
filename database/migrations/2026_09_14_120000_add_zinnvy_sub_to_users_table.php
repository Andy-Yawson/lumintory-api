<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Continue with Zinnvy" (Zinnvy Identity OAuth login). Storing the OIDC
 * `sub` claim (a stable UUID) rather than trusting email as the join key
 * long-term — email is only used to link a Zinnvy account to an *existing*
 * Inventory account the first time someone signs in this way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('zinnvy_sub')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('zinnvy_sub');
        });
    }
};
