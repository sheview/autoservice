<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The equipment number (เลขครุภัณฑ์) the owner gives a device. Optional; when given it is
    // unique within the tenant, deleted assets included (like asset_code). Existing assets stay empty.
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('property_no', 100)->nullable()->after('serial_number');
            $table->unique(['tenant_id', 'property_no']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'property_no']);
            $table->dropColumn('property_no');
        });
    }
};
