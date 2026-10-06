<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - room_access_visits: each time the team went in and came out on a request. A one-off request
 *   has one; a standing request (recurrence) one per day it was used. Reports read these.
 * - room_access_requests.recurrence: a standing request, approved once for a period: the weekdays
 *   and the hours of each visit ({weekdays: [1..7], start_time: "09:00", end_time: "12:00"});
 *   planned_start / planned_end are then the first and last day of the period.
 * - the alerts already sent for a request (each once): starting soon, still inside after the
 *   planned end, waiting too long for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_access_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->string('entered_by_name')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->string('exited_by_name')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'entered_at']);
        });
        Rls::enable('room_access_visits');

        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->json('recurrence')->nullable()->after('planned_end');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overstay_alerted_at')->nullable();
            $table->timestamp('approval_alerted_at')->nullable();
        });

        // Visits already recorded on the requests themselves.
        Rls::noForce('room_access_requests');
        Rls::noForce('room_access_visits');
        DB::statement('INSERT INTO room_access_visits (tenant_id, request_id, entered_at, entered_by_name, exited_at, exited_by_name, created_at, updated_at)
            SELECT tenant_id, id, entered_at, entered_by_name, exited_at, exited_by_name, entered_at, coalesce(exited_at, entered_at)
            FROM room_access_requests WHERE entered_at IS NOT NULL');
        Rls::force('room_access_requests');
        Rls::force('room_access_visits');
    }

    public function down(): void
    {
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'reminded_at', 'overstay_alerted_at', 'approval_alerted_at']);
        });
        Schema::dropIfExists('room_access_visits');
    }
};
