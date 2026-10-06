<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one company lets another see of its data (cross-company sharing): from_tenant's people
 * may use the abilities on to_tenant's data. Platform data, like "tenants": no tenant_id and no
 * row level security; only read and written through App\Modules\Platform\CrossTenant\ShareGateway
 * and the Platform actions, which always name the tenants they work on.
 *
 * A share starts "pending" until the receiving company's admin accepts it, or is made "active" by
 * the superadmin; either side may revoke it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('to_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->json('abilities')->default('[]'); // e.g. ["parts.view", "assets.view"]
            $table->json('roles')->default('[]'); // role names of from_tenant (or central roles) that may use it
            $table->string('status', 20); // pending | active | revoked
            $table->string('reason')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('granted_by_name')->nullable();
            $table->string('accepted_by_name')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['from_tenant_id', 'to_tenant_id']);
            $table->index(['to_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_shares');
    }
};
