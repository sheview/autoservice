<?php

use App\Modules\Identity\Actions\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Purchasing may now edit and delete assets (PermissionCatalog::DEFAULT_ROLES). Gives the
 * purchasing role of every company the two grants; grants a company already has are left as
 * they are. Runs across every tenant on purpose (platform data change, by role name and permission).
 */
return new class extends Migration
{
    private const GRANTS = ['assets.update' => 'all', 'assets.delete' => 'all'];

    public function up(): void
    {
        app(SyncPermissions::class)->handle();
        $permissions = DB::table('permissions')->pluck('id', 'name');

        foreach (DB::table('roles')->where('name', 'purchasing')->pluck('id') as $roleId) {
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
        $roles = DB::table('roles')->where('name', 'purchasing')->pluck('id');
        $permissions = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $roles)->whereIn('permission_id', $permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
