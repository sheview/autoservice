<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the roles matrix: for each role given, exactly these grants (permission => scope).
 * The admin role always holds everything and is never changed here. The customer account role
 * reaches its customer only; the company's roles reach all, their branch or their own records.
 * Each role that changed is written to the activity log with what was added, removed or rescoped.
 */
class SaveRoleMatrix
{
    public function __construct(private SyncRoleGrants $syncGrants) {}

    /**
     * @param  array<int|string, array<string, string>>  $matrix  role id => [permission => scope]
     * @return int the number of roles that changed
     */
    public function handle(array $matrix, User $actor): int
    {
        $roles = Role::query()->whereKey(array_keys($matrix))->get()->keyBy('id');
        $allowed = PermissionCatalog::tenantPermissions();
        $changed = 0;

        DB::transaction(function () use ($matrix, $roles, $allowed, $actor, &$changed) {
            foreach ($matrix as $roleId => $grants) {
                $role = $roles->get((int) $roleId);
                if ($role === null) {
                    throw ValidationException::withMessages(['matrix' => __('identity.roles.unknown_role')]);
                }
                if ($role->name === PermissionCatalog::ADMIN_ROLE) {
                    throw ValidationException::withMessages(['matrix' => __('identity.roles.admin_locked')]);
                }

                $external = $role->name === PermissionCatalog::CUSTOMER_ROLE;
                $clean = [];
                foreach ($grants as $permission => $scope) {
                    if (! in_array($permission, $allowed, true)) {
                        throw ValidationException::withMessages(['matrix' => __('identity.roles.unknown_permission', ['permission' => $permission])]);
                    }
                    $valid = $external
                        ? [PermissionCatalog::SCOPE_CUSTOMER]
                        : [PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH, PermissionCatalog::SCOPE_OWN];
                    if (! in_array($scope, $valid, true)) {
                        throw ValidationException::withMessages(['matrix' => __('identity.roles.bad_scope', ['role' => $role->label ?? $role->name])]);
                    }
                    $clean[$permission] = $scope;
                }

                $diff = $this->syncGrants->handle($role, $clean);
                if ($diff['added'] === [] && $diff['removed'] === [] && $diff['rescoped'] === []) {
                    continue;
                }

                $changed++;
                activity()->performedOn($role)->causedBy($actor)->event('role_grants_updated')
                    ->withProperties(['role' => $role->name, ...$diff])
                    ->log('แก้ไขสิทธิ์บทบาท '.($role->label ?? $role->name));
            }
        });

        return $changed;
    }
}
