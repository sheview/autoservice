<?php

use App\Modules\Identity\Actions\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The register of outside technicians (field-technicians.view / .manage, PermissionCatalog): the
 * company admin and helpdesk roles of every company get both, as new companies do. Grants a
 * company already has are left as they are. Runs across every tenant on purpose (platform data
 * change, by role name and permission).
 */
return new class extends Migration
{
    private const ROLES = ['admin_company', 'helpdesk'];

    private const GRANTS = ['field-technicians.view' => 'all', 'field-technicians.manage' => 'all'];

    public function up(): void
    {
        app(SyncPermissions::class)->handle();
        $permissions = DB::table('permissions')->pluck('id', 'name');

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            foreach (self::GRANTS as $permission => $scope) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissions[$permission], 'scope' => $scope,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roles = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');
        $permissions = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $roles)->whereIn('permission_id', $permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
