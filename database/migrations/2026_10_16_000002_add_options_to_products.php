<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product options (Colour, Size, Thickness…): up to 3 per product. Each variation is still one
 * stock item; it now also records which option values it is, e.g. {"Colour":"Brown","Size":"42"},
 * and its name is built from them ("Brown / 42"). Existing variations keep working unchanged
 * (no option_names = one unnamed option whose values are the variation names).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->json('option_names')->nullable()->after('size'));
        Schema::table('product_variations', fn (Blueprint $t) => $t->json('options')->nullable()->after('name'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('option_names'));
        Schema::table('product_variations', fn (Blueprint $t) => $t->dropColumn('options'));
    }
};
