<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the asset an issue/loan form takes: an asset bought by the lot (24 cables) is lent
 * a few at a time. Forms that are asked for or out hold their quantity; the rest is available.
 * Existing forms took the whole (single) asset, so they default to 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_checkouts', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('asset_checkouts', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
