<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How far a permission reaches for a user (PermissionCatalog scopes), and the matching filter
 * for queries and check for single records. Every list and record of a resource goes through
 * here with its own columns:
 *
 *   DataScope::constrain($query, $user, 'tickets.view', own: fn ($q) => $q->where('assignee_id', $user->id));
 *   DataScope::covers($ticket, $user, 'tickets.view', own: fn ($t) => $t->assignee_id === $user->id);
 *
 * branch = the "branch_id" column is the user's branch or empty (pass branch: null for a resource
 * without branches: then the whole company); customer = the "customer_id" column is the account's
 * customer (pass customer: null when the resource has no customer: then nothing). A customer
 * account never reaches beyond its customer, whatever its role says. A superadmin inside the
 * tenant and central staff reach the whole company.
 */
class DataScope
{
    /**
     * The widest scope any of the user's roles grants for the permission; null = not granted.
     */
    public static function of(User $user, string $permission): ?string
    {
        if (! $user->checkPermissionTo($permission)) {
            return null;
        }
        if ($user->customer_id !== null) {
            return PermissionCatalog::SCOPE_CUSTOMER;
        }
        if (app(Impersonation::class)->actingAs($user)) {
            return PermissionCatalog::SCOPE_ALL;
        }

        $scopes = self::grantedScopes($user);

        // Granted otherwise (directly to the user): the whole company, like before scopes.
        return $scopes[$permission] ?? PermissionCatalog::SCOPE_ALL;
    }

    /**
     * Narrows $query to the records the user may reach with $permission.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @param  string|Closure|null  $branch  the branch column (or a filter given the branch id); null = no branches
     * @param  string|Closure|null  $customer  the customer column (or a filter given the customer id); null = no customer
     * @param  Closure|null  $own  the filter of the user's own records; null = none are "own"
     * @return T
     */
    public static function constrain(Builder $query, User $user, string $permission, string|Closure|null $branch = 'branch_id',
        string|Closure|null $customer = 'customer_id', ?Closure $own = null): Builder
    {
        return match (self::of($user, $permission)) {
            PermissionCatalog::SCOPE_ALL => $query,
            PermissionCatalog::SCOPE_BRANCH => match (true) {
                $branch === null => $query,
                $branch instanceof Closure => $branch($query, $user->branch_id),
                default => $query->where(fn ($q) => $q->whereNull($branch)->when($user->branch_id, fn ($q, $id) => $q->orWhere($branch, $id))),
            },
            PermissionCatalog::SCOPE_CUSTOMER => match (true) {
                $customer === null => $query->whereRaw('false'),
                $customer instanceof Closure => $customer($query, $user->customer_id),
                default => $query->where($customer, $user->customer_id),
            },
            PermissionCatalog::SCOPE_OWN => $own ? $query->where(fn ($q) => $own($q)) : $query->whereRaw('false'),
            default => $query->whereRaw('false'),
        };
    }

    /**
     * Whether the user reaches $model with $permission. Same arguments as constrain(), as checks
     * on the record.
     *
     * @param  string|Closure|null  $branch  the branch attribute (or a check given the branch id)
     * @param  string|Closure|null  $customer  the customer attribute (or a check given the customer id)
     * @param  Closure|null  $own  whether the record is the user's own
     */
    public static function covers(Model $model, User $user, string $permission, string|Closure|null $branch = 'branch_id',
        string|Closure|null $customer = 'customer_id', ?Closure $own = null): bool
    {
        return match (self::of($user, $permission)) {
            PermissionCatalog::SCOPE_ALL => true,
            PermissionCatalog::SCOPE_BRANCH => match (true) {
                $branch === null => true,
                $branch instanceof Closure => (bool) $branch($model, $user->branch_id),
                default => $model->getAttribute($branch) === null || (int) $model->getAttribute($branch) === (int) $user->branch_id,
            },
            PermissionCatalog::SCOPE_CUSTOMER => match (true) {
                $customer === null => false,
                $customer instanceof Closure => (bool) $customer($model, $user->customer_id),
                default => $model->getAttribute($customer) !== null && (int) $model->getAttribute($customer) === (int) $user->customer_id,
            },
            PermissionCatalog::SCOPE_OWN => $own !== null && (bool) $own($model),
            default => false,
        };
    }

    /**
     * Permission => widest scope over the user's roles in the current tenant, worked out once per request.
     *
     * @return array<string, string>
     */
    private static function grantedScopes(User $user): array
    {
        $key = 'data_scope.'.$user->id.'.'.app(TenantContext::class)->id();
        $request = request();
        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $rank = array_flip(PermissionCatalog::SCOPES);
        $scopes = [];
        $rows = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('role_has_permissions.role_id', $user->roles()->pluck('roles.id'))
            ->get(['permissions.name', 'role_has_permissions.scope']);
        foreach ($rows as $row) {
            $current = $scopes[$row->name] ?? null;
            if ($current === null || ($rank[$row->scope] ?? 99) < ($rank[$current] ?? 99)) {
                $scopes[$row->name] = $row->scope;
            }
        }

        $request->attributes->set($key, $scopes);

        return $scopes;
    }

    /** After roles change within a request (tests, the roles page). */
    public static function forget(): void
    {
        $attributes = request()->attributes;
        foreach (array_keys($attributes->all()) as $key) {
            if (str_starts_with($key, 'data_scope.')) {
                $attributes->remove($key);
            }
        }
    }
}
