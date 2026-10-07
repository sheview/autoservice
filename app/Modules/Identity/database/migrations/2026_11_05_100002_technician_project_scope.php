<?php

use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Technicians now see the work of the projects whose team they are on (scope "project", which
 * also covers everything "own" did). Moves the technician role of every company to it, for the
 * grants still at their old default "own"; grants a company has changed are left alone. Runs
 * across every tenant on purpose (platform data change, by role name and permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(PermissionCatalog::SCOPE_OWN, PermissionCatalog::SCOPE_PROJECT);
    }

    public function down(): void
    {
        $this->move(PermissionCatalog::SCOPE_PROJECT, PermissionCatalog::SCOPE_OWN);
    }

    private function move(string $from, string $to): void
    {
        $roleIds = DB::table('roles')->where('name', 'technician')->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('name', PermissionCatalog::TECHNICIAN_PROJECT_GRANTS)->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->where('scope', $from)
            ->update(['scope' => $to]);
    }
};
