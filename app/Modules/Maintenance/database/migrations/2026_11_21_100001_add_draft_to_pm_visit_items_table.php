<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the technician typed and has not saved yet (autosaved while filling in on site):
 * {result, answers, remarks, note, not_inspected_reason, instrument_id, saved_at}. Kept apart from the
 * recorded result, which only changes when the item is saved; saving clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->json('draft')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->dropColumn('draft');
        });
    }
};
