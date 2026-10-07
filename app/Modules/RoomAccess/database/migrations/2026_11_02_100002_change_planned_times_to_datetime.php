<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planned times of a request (and the links that last until then) are DATETIME on MariaDB:
 * a TIMESTAMP stops at 2038-01-19, and a standing request may run as long as its MA contract,
 * or a date may be typed wrong (2099), which failed the save with a server error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->dateTime('planned_start')->change();
            $table->dateTime('planned_end')->change();
        });
        Schema::table('room_access_tokens', fn (Blueprint $table) => $table->dateTime('expires_at')->change());
    }

    public function down(): void
    {
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->timestamp('planned_start')->change();
            $table->timestamp('planned_end')->change();
        });
        Schema::table('room_access_tokens', fn (Blueprint $table) => $table->timestamp('expires_at')->change());
    }
};
