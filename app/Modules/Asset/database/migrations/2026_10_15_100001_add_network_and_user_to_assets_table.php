<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network address of a device (searched often in MA work, so real columns) and who uses it day to day,
 * which the job sheet fills in when a ticket is opened for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('location');
            $table->string('mac_address', 17)->nullable()->after('ip_address'); // AA:BB:CC:DD:EE:FF
            $table->string('used_by')->nullable()->after('mac_address');
            $table->string('department')->nullable()->after('used_by');

            $table->index(['tenant_id', 'ip_address']);
            $table->index(['tenant_id', 'mac_address']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'ip_address']);
            $table->dropIndex(['tenant_id', 'mac_address']);
            $table->dropColumn(['ip_address', 'mac_address', 'used_by', 'department']);
        });
    }
};
