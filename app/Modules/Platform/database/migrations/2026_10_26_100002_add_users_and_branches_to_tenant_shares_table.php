<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A share may name people (user_ids of the viewing company, or central staff) besides roles,
 * and limit the shared assets to some branches of the owner (branch_ids; empty = every branch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_shares', function (Blueprint $table) {
            $table->json('user_ids')->default('[]')->after('roles');
            $table->json('branch_ids')->default('[]')->after('user_ids');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_shares', function (Blueprint $table) {
            $table->dropColumn(['user_ids', 'branch_ids']);
        });
    }
};
