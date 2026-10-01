<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A ticket is about a registered asset (asset_id) or a device that is not in the system,
    // described here by whoever opened it. Before work starts, staff check whether the device is
    // under warranty (warranty_status: in_warranty | out_of_warranty). Existing tickets keep nulls.
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('device_name')->nullable()->after('asset_id');
            $table->string('device_brand')->nullable()->after('device_name');
            $table->string('device_model')->nullable()->after('device_brand');
            $table->string('device_serial')->nullable()->after('device_model');
            $table->boolean('device_serial_unknown')->default(false)->after('device_serial');
            $table->string('device_location')->nullable()->after('device_serial_unknown');

            $table->string('warranty_status', 20)->nullable()->after('device_location');
            $table->date('warranty_expires_on')->nullable()->after('warranty_status');
            $table->string('warranty_checked_by_name')->nullable()->after('warranty_expires_on');
            $table->timestamp('warranty_checked_at')->nullable()->after('warranty_checked_by_name');

            $table->index(['tenant_id', 'device_serial']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'device_serial']);
            $table->dropColumn([
                'device_name', 'device_brand', 'device_model', 'device_serial', 'device_serial_unknown', 'device_location',
                'warranty_status', 'warranty_expires_on', 'warranty_checked_by_name', 'warranty_checked_at',
            ]);
        });
    }
};
