<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entering and leaving a room:
 * - server_rooms.guard_link: the room's guards / caretakers get a link of each approved request to
 *   record entering and leaving themselves (off unless the room says so).
 * - room_access_requests: who recorded entering / leaving, and who confirmed the equipment taken
 *   out after the work summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_rooms', function (Blueprint $table) {
            $table->boolean('guard_link')->default(false)->after('entrants_accept_self');
        });
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->string('entered_by_name')->nullable()->after('entered_at');
            $table->string('exited_by_name')->nullable()->after('exited_at');
            $table->string('items_confirmed_by_name')->nullable()->after('items_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->dropColumn(['entered_by_name', 'exited_by_name', 'items_confirmed_by_name']);
        });
        Schema::table('server_rooms', function (Blueprint $table) {
            $table->dropColumn('guard_link');
        });
    }
};
