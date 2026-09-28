<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Migrations\PennantMigration;

// laravel/pennant table. Holds which modules the platform switched on for each tenant
// (scope "App\Modules\Tenancy\Models\Tenant|{id}"). This is platform configuration written by
// the platform about a tenant, like the tenants table itself, not data of the tenant, so it
// has no tenant_id and no RLS: the platform must be able to change it for any tenant.
return new class extends PennantMigration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
