<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Support\Impersonation;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for tenant data. A module policy only sets $module:
 *
 *   class AssetPolicy extends TenantPolicy { protected string $module = 'asset'; }
 *
 * Each ability needs the permission "{module}.{ability}" and, for a record, that the record
 * is in the user's tenant and in the user's branch (unless the user has branch.all).
 * For a customer account (user with customer_id) the record must belong to that customer instead.
 * Override branchIdOf() when the branch is not the "branch_id" column.
 * A superadmin who is impersonating passes every check (Gate::before in IdentityServiceProvider).
 * Central staff who entered the tenant are checked here like anyone else, with the permissions
 * of their platform role and the tenant they entered.
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
        if ((int) $model->getAttribute('tenant_id') !== $this->tenantIdOf($user)) {
            return false;
        }

        // A customer account only sees records of its own customer; anything without a
        // customer (branches, users, categories, ...) is out of its reach.
        if ($user->customer_id !== null) {
            return $model->getAttribute('customer_id') !== null
                && (int) $model->getAttribute('customer_id') === (int) $user->customer_id;
        }

        $branchId = $this->branchIdOf($model);

        return $branchId === null
            || $user->checkPermissionTo(PermissionCatalog::ALL_BRANCHES)
            || (int) $branchId === (int) $user->branch_id;
    }

    /**
     * The tenant the user is working in: their own, or the one a platform user has entered.
     */
    protected function tenantIdOf(User $user): int
    {
        $impersonation = app(Impersonation::class);

        return (int) ($impersonation->actingAs($user) ? $impersonation->tenant()->id : $user->tenant_id);
    }

    /**
     * Central staff of the platform working inside this tenant. They are not users of it, so
     * nobody can assign work to them: where a rule says "be the assignee", they count as one.
     */
    protected function actsFromPlatform(User $user): bool
    {
        return app(Impersonation::class)->actingAs($user);
    }

    protected function branchIdOf(Model $model): ?int
    {
        return $model->getAttribute('branch_id');
    }
}
