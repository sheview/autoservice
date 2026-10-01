<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device's IP address (copied from the asset, or typed for a device not in the system) and the
 * repair report of the job sheet: what caused the problem, extra cost and who approved the repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('device_ip', 45)->nullable()->after('device_location');

            $table->text('cause')->nullable()->after('warranty_checked_at');
            $table->bigInteger('extra_cost')->nullable()->after('cause'); // satang
            $table->string('approver_name')->nullable()->after('extra_cost');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['device_ip', 'cause', 'extra_cost', 'approver_name']);
        });
    }
};
