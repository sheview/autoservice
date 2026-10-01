<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project (MA contract) a purchase is for, so a project's summary lists what was bought for it.
 * Optional: purchases for the company itself, and every request before this, have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
