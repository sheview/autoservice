<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project (MA contract) an issue/loan form is for, so a project's summary lists what went out
 * for it. Optional: forms not for a customer's job, and every form before this, have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_checkouts', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
