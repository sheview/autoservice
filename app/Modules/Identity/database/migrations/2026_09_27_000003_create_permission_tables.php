<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables in teams mode, team key = tenant_id.
 *
 * permissions           global catalogue ({module}.{action}), no tenant
 * roles                 per tenant (each tenant can edit its own roles)  -> RLS
 * model_has_roles       per tenant                                       -> RLS
 * model_has_permissions per tenant                                       -> RLS
 * role_has_permissions  join of a tenant role and a global permission (ids only)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('label');
            $table->string('guard_name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'guard_name']);
        });
        Rls::enable('roles');

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->index(['model_id', 'model_type']);
            $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type']);
        });
        Rls::enable('model_has_permissions');

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->index(['model_id', 'model_type']);
            $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type']);
        });
        Rls::enable('model_has_roles');

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
