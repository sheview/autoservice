<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One person working in several companies (technicians who rotate between sister companies):
// each company keeps its own user row (role, branch, active, assignable), and the rows of the
// other companies point to the person's main account, the only one that logs in
// (Platform\CrossTenant\LinkedAccounts). One linked row per company and main account.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('login_user_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'login_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'login_user_id']);
            $table->dropConstrainedForeignId('login_user_id');
        });
    }
};
