<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the "SLA breached" e-mail of each clock was sent, so it is sent once per ticket and clock.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('response_breach_notified_at')->nullable()->after('responded_at');
            $table->timestamp('resolve_breach_notified_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['response_breach_notified_at', 'resolve_breach_notified_at']);
        });
    }
};
