<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * The only door into another company's data. A user working in company A may use an ability
 * (parts.view, ...) on company B when a share A -> B is active, not expired, holds the ability
 * and lists one of the user's roles in their own (home) company — a company role, or a central
 * role for platform staff working inside A — or names the user. Customer accounts never pass.
 *
 * run() switches the tenant context to B for the callback only, so B's row level security
 * applies as usual: the callback reads B's data as B, and nothing else of B's leaks.
 * Whether the user holds the ability in A itself is the caller's check.
 */
class ShareGateway
{
    public function __construct(private TenantContext $context) {}

    /**
     * The companies the user may reach with the ability from the company they work in now.
     *
     * @return Collection<int, Tenant>
     */
    public function targets(User $user, string $ability): Collection
    {
        return $this->shares($user, $ability)
            ->map(fn (TenantShare $share) => $share->toTenant)
            ->sortBy('name')
            ->values();
    }

    /**
     * Runs the callback inside company $target, when the user may reach it with the ability.
     * The callback gets the share, to keep to what it allows (e.g. its branch_ids).
     *
     * @param  callable(TenantShare): mixed  $callback
     */
    public function run(User $user, Tenant $target, string $ability, callable $callback): mixed
    {
        $share = $this->shares($user, $ability)->firstWhere('to_tenant_id', $target->id);
        abort_if($share === null, 403);

        return $this->context->run($target, fn () => $callback($share));
    }

    /**
     * The shares in force that reach the user, from the company they work in now.
     *
     * @return Collection<int, TenantShare>
     */
    private function shares(User $user, string $ability): Collection
    {
        $from = $this->context->id();
        if ($from === null || $user->customer_id !== null) {
            return collect();
        }
        $roles = $this->homeRoles($user);

        return TenantShare::query()
            ->with('toTenant')
            ->where('from_tenant_id', $from)
            ->where('status', TenantShare::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->whereJsonContains('abilities', $ability)
            ->get()
            ->filter(fn (TenantShare $share) => array_intersect($share->roles, $roles) !== []
                || in_array((int) $user->id, array_map('intval', $share->user_ids), true))
            ->filter(fn (TenantShare $share) => $share->toTenant !== null && ! $share->toTenant->is_platform)
            ->values();
    }

    /**
     * The user's role names in their own company (for platform staff: their central role).
     *
     * @return list<string>
     */
    private function homeRoles(User $user): array
    {
        return $this->context->run($user->tenant_id, fn () => $user->roles()->pluck('name')->all());
    }
}
