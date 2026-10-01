<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Platform\Support\Impersonation;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for tenant data. A resource policy sets $resource (the first part of its
 * permissions, PermissionCatalog) and, where they differ, how abilities map to actions and
 * which records are the user's own:
 *
 *   class AssetPolicy extends TenantPolicy { protected string $resource = 'assets'; }
 *
 * Each ability needs the permission "{resource}.{action}" and, for a record, that the record is
 * in the user's tenant and within the scope the user's role grants for that permission
 * (DataScope: all / branch / own / customer). Override owns() to say what "own" means, and set
 * $branchColumn / $customerColumn to null for a resource without branches / customers.
 * A superadmin who is impersonating passes every check (Gate::before in IdentityServiceProvider).
 * Central staff who entered the tenant are checked here like anyone else, with the permissions
 * of their platform role over the whole company.
 */
abstract class TenantPolicy
{
    protected string $resource;

    /** @var array<string, string> ability => action of the permission, where they differ */
    protected array $actions = [];

    protected ?string $branchColumn = 'branch_id';

    protected ?string $customerColumn = 'customer_id';

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->permits($user, 'view') && $this->inScope($user, $model, 'view');
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'update') && $this->inScope($user, $model, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'delete') && $this->inScope($user, $model, 'delete');
    }

    /** The permission an ability needs. */
    protected function permission(string $ability): string
    {
        return "{$this->resource}.".($this->actions[$ability] ?? $ability);
    }

    protected function permits(User $user, string $ability): bool
    {
        return $user->checkPermissionTo($this->permission($ability));
    }

    /**
     * The record is in the user's tenant and within the scope of the ability's permission.
     */
    protected function inScope(User $user, Model $model, string $ability = 'view'): bool
    {
        if ((int) $model->getAttribute('tenant_id') !== $this->tenantIdOf($user)) {
            return false;
        }

        return DataScope::covers($model, $user, $this->permission($ability), $this->branchColumn, $this->customerColumn,
            fn (Model $record) => $this->owns($user, $record));
    }

    /** Whether the record is the user's own (for scope "own"). */
    protected function owns(User $user, Model $model): bool
    {
        return false;
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
}
