<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer must sign on the technician's phone when a job is closed. Set on the
 * customer; a contract may say otherwise (null = as its customer says).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('require_signature')->default(false)->after('notes');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('require_signature')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('require_signature'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('require_signature'));
    }
};
