<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a role exactly these grants (permission => scope), taking away the others. The scope is
 * kept on role_has_permissions next to spatie's row. Returns what changed, for the activity log.
 */
class SyncRoleGrants
{
    /**
     * @param  array<string, string>  $grants  permission name => scope (PermissionCatalog::SCOPES)
     * @return array{added: array<string, string>, removed: list<string>, rescoped: array<string, array{0: string, 1: string}>}
     */
    public function handle(Role $role, array $grants): array
    {
        $before = $this->grantsOf($role);

        DB::transaction(function () use ($role, $grants) {
            $ids = Permission::query()->whereIn('name', array_keys($grants))->where('guard_name', 'web')->pluck('id', 'name');

            DB::table('role_has_permissions')->where('role_id', $role->id)->whereNotIn('permission_id', $ids->values())->delete();
            foreach ($grants as $name => $scope) {
                if (! isset($ids[$name])) {
                    continue;
                }
                $scope = in_array($scope, PermissionCatalog::SCOPES, true) ? $scope : PermissionCatalog::SCOPE_ALL;
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $ids[$name]],
                    ['scope' => $scope],
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role->unsetRelation('permissions');
        DataScope::forget();

        $after = $this->grantsOf($role);
        $rescoped = [];
        foreach (array_intersect_key($after, $before) as $name => $scope) {
            if ($before[$name] !== $scope) {
                $rescoped[$name] = [$before[$name], $scope];
            }
        }

        return [
            'added' => array_diff_key($after, $before),
            'removed' => array_keys(array_diff_key($before, $after)),
            'rescoped' => $rescoped,
        ];
    }

    /**
     * @return array<string, string> permission name => scope
     */
    public function grantsOf(Role $role): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->id)
            ->orderBy('permissions.name')
            ->pluck('role_has_permissions.scope', 'permissions.name')
            ->all();
    }
}
