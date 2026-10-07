<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff shared between sister companies: the people of from_tenant who hold one of the roles
 * (e.g. technician) may also work for to_tenant. Each of them gets a linked account there
 * (users.login_user_id), kept in step by SyncStaffPool, so to_tenant can assign them its MA work.
 * Platform data, like "tenant_shares": no tenant_id; set by the superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('to_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->json('roles'); // role names of from_tenant, e.g. ["technician"]
            $table->boolean('is_active')->default(true);
            $table->string('created_by_name')->nullable();
            $table->timestamps();
            $table->unique(['from_tenant_id', 'to_tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_pools');
    }
};
