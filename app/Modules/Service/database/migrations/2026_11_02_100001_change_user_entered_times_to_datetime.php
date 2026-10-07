<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Times people type in (an appointment, a personal event) are DATETIME on MariaDB: a TIMESTAMP
 * stops at 2038-01-19 and a later date (a typo such as 2099) failed the save with a server
 * error. Values keep their wall-clock time (the connection is always +07:00).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dateTime('appointment_at')->nullable()->change());
        Schema::table('personal_events', function (Blueprint $table) {
            $table->dateTime('starts_at')->change();
            $table->dateTime('ends_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->timestamp('appointment_at')->nullable()->change());
        Schema::table('personal_events', function (Blueprint $table) {
            $table->timestamp('starts_at')->change();
            $table->timestamp('ends_at')->nullable()->change();
        });
    }
};
