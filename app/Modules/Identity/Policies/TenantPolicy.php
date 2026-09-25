<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for tenant data. A module policy only sets $module:
 *
 *   class AssetPolicy extends TenantPolicy { protected string $module = 'asset'; }
 *
 * Each ability needs the permission "{module}.{ability}" and, for a record, that the record
 * is in the user's tenant and in the user's branch (unless the user has branch.all).
 * Override branchIdOf() when the branch is not the "branch_id" column.
 * A superadmin who is impersonating passes every check (Gate::before in IdentityServiceProvider).
 */
abstract class TenantPolicy
{
    protected string $module;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->permits($user, 'view') && $this->inScope($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'update') && $this->inScope($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'delete') && $this->inScope($user, $model);
    }

    protected function permits(User $user, string $action): bool
    {
        return $user->checkPermissionTo("{$this->module}.{$action}");
    }

    protected function inScope(User $user, Model $model): bool
    {
        if ((int) $model->getAttribute('tenant_id') !== (int) $user->tenant_id) {
            return false;
        }

        $branchId = $this->branchIdOf($model);

        return $branchId === null
            || $user->checkPermissionTo(PermissionCatalog::ALL_BRANCHES)
            || (int) $branchId === (int) $user->branch_id;
    }

    protected function branchIdOf(Model $model): ?int
    {
        return $model->getAttribute('branch_id');
    }
}
