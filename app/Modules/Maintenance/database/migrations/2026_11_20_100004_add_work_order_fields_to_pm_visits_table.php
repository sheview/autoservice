<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - onsite_at / departed_at: the technician's "arrived" / "left" taps.
 * - office_data: office-use figures of the round (travel cost, overtime, ...), JSON.
 * - locked_at: set when the customer signs; results cannot change after it.
 * - revision_of_id: a corrected copy of a locked work order points at the one it corrects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->dateTime('onsite_at')->nullable()->after('started_at');
            $table->dateTime('departed_at')->nullable()->after('onsite_at');
            $table->json('office_data')->nullable()->after('summary');
            $table->timestamp('locked_at')->nullable()->after('closed_by');
            $table->foreignId('revision_of_id')->nullable()->after('locked_at')->constrained('pm_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_of_id');
            $table->dropColumn(['onsite_at', 'departed_at', 'office_data', 'locked_at']);
        });
    }
};
