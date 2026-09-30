<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            // Set when the "round coming up" e-mail was sent; cleared when the date or technician changes.
            $table->timestamp('reminded_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
