<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which part of the banner photo to show (focus point, %) and how far to zoom in, set by dragging in Settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedTinyInteger('banner_focus_x')->default(50)->after('banner_path');
            $table->unsignedTinyInteger('banner_focus_y')->default(50)->after('banner_focus_x');
            $table->unsignedSmallInteger('banner_zoom')->default(100)->after('banner_focus_y');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['banner_focus_x', 'banner_focus_y', 'banner_zoom']));
    }
};
