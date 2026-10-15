<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note per checklist question ({key: text}), beside the note on the whole piece of equipment.
 * Photos of one question carry its key as a media custom property ("question").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->json('remarks')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
