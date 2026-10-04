<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IP address a ticket is about (IP management in the Asset module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('ip_address_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'ip_address_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'ip_address_id']);
            $table->dropConstrainedForeignId('ip_address_id');
        });
    }
};
