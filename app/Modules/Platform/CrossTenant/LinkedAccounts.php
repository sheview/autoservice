<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One person, several companies. Each company keeps its own user row (role, branch, active,
 * assignable to its work); the rows of the other companies point to the person's main account
 * (users.login_user_id). Only the main account logs in; switching company logs in as the
 * person's row there, so everything inside a company still runs as one of its own users.
 *
 * Reads users across tenants, so it lives here and nowhere else.
 */
class LinkedAccounts
{
    /** The account this person logs in with. */
    public function mainOf(User $user): User
    {
        if ($user->login_user_id === null) {
            return $user;
        }

        return IdentityLookup::run(fn () => $this->users()->find($user->login_user_id)) ?? $user;
    }

    /**
     * The person's rows in every company: the main account first, then the linked ones.
     *
     * @return Collection<int, User>
     */
    public function accountsOf(User $user): Collection
    {
        $main = $this->mainOf($user);

        return IdentityLookup::run(fn () => $this->users()
            ->where(fn (Builder $q) => $q->whereKey($main->id)->orWhere('login_user_id', $main->id))
            ->orderByRaw('login_user_id is not null')
            ->orderBy('id')
            ->get());
    }

    /** The person's active row in a company, or null. */
    public function accountIn(User $user, int $tenantId): ?User
    {
        return $this->accountsOf($user)->first(fn (User $account) => $account->tenant_id === $tenantId && $account->is_active);
    }

    /**
     * The companies this person can switch to (active rows in active companies), with the current one marked.
     *
     * @return list<array{ulid: string, name: string, current: bool}>
     */
    public function companiesOf(User $user): array
    {
        $accounts = $this->accountsOf($user)->filter(fn (User $account) => $account->is_active);
        if ($accounts->count() < 2) {
            return [];
        }

        return Tenant::whereIn('id', $accounts->pluck('tenant_id'))
            ->orderBy('name')
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->isActive() && ! $tenant->is_platform)
            ->map(fn (Tenant $tenant) => ['ulid' => $tenant->ulid, 'name' => $tenant->name, 'current' => $tenant->id === $user->tenant_id])
            ->values()
            ->all();
    }

    /**
     * An account another company's row can be linked to: a main account (not itself linked) of a
     * customer company other than $tenantId, found by its e-mail.
     */
    public function linkableMain(string $email, int $tenantId): ?User
    {
        $user = IdentityLookup::run(fn () => $this->users()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->whereNull('login_user_id')
            ->where('tenant_id', '!=', $tenantId)
            ->first());

        return $user !== null && Tenant::whereKey($user->tenant_id)->where('is_platform', false)->exists() ? $user : null;
    }

    /** E-mail of a main account by id (shown on the linked row in its company). */
    public function emailOf(int $userId): ?string
    {
        return IdentityLookup::run(fn () => $this->users()->whereKey($userId)->value('email'));
    }

    /** Whether rows of other companies are linked to this account (then it cannot be linked itself). */
    public function hasLinkedAccounts(User $user): bool
    {
        return IdentityLookup::run(fn () => $this->users()->where('login_user_id', $user->id)->exists());
    }

    /**
     * @return Builder<User>
     */
    private function users(): Builder
    {
        // A person's rows are in different companies by design: this lookup must see them all.
        // Callers only get the person's own rows (or one main account by e-mail), never a listing.
        return User::query()->withoutGlobalScope(TenantScope::class);
    }
}
