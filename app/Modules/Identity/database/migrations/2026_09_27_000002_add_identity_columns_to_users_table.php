<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every user belongs to a tenant; superadmins belong to the platform tenant.
            $table->foreignId('tenant_id')->nullable(false)->change();
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->index()->constrained()->nullOnDelete();
            $table->string('employee_code')->nullable()->after('email');
            $table->string('position')->nullable()->after('employee_code');
            $table->string('phone')->nullable()->after('position');
            $table->json('service_lines')->default('[]')->after('phone');
            $table->boolean('is_active')->default(true)->after('service_lines');
            $table->softDeletes();
        });

        // Login and session lookups happen before the tenant is known; they run
        // through IdentityLookup, which the policy allows explicitly.
        Rls::enable('users', identityLookup: true);
    }

    public function down(): void
    {
        Rls::disable('users');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['employee_code', 'position', 'phone', 'service_lines', 'is_active', 'deleted_at']);
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }
};
