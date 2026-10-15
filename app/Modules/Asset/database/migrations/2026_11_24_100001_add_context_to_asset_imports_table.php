<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an asset import was asked from, when not the asset page: e.g. a PM plan, its contract and
 * its own columns ({"pm_plan_id": 5, "contract_id": 12, "customer_id": 3, "columns": {...}}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_imports', function (Blueprint $table) {
            $table->json('context')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('asset_imports', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
