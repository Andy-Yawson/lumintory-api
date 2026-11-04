<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_jobs', function (Blueprint $t) {
            $t->string('track_token', 40)->nullable()->unique();   // the customer's tracking link: the token is the credential
            $t->text('handover_code_enc')->nullable();             // encrypted, so the tracking page can show the customer their own code
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_jobs', fn (Blueprint $t) => $t->dropColumn(['track_token', 'handover_code_enc']));
    }
};
