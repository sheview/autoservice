<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff of every customer company, for the platform's "ช่างหลายบริษัท" page: each person's
 * main account and the companies their (linked) accounts are in (LinkedAccounts).
 * Platform use only (superadmin, outside any company).
 */
class CompanyStaff
{
    /**
     * Main accounts of company staff (not customer accounts), each with where they can work: home
     * (their own company), on (companies where their linked account is active), off (deactivated).
     */
    public function directory(string $search, ?int $tenantId, int $perPage = 25): LengthAwarePaginator
    {
        $customerTenants = Tenant::where('is_platform', false)->pluck('id');

        $page = IdentityLookup::run(fn () => $this->users()
            ->whereIn('tenant_id', $customerTenants)
            ->whereNull('login_user_id')
            ->whereNull('customer_id')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")))
            ->when($tenantId, fn (Builder $q, int $id) => $q->where(fn (Builder $q) => $q
                ->where('tenant_id', $id)
                ->orWhereIn('id', $this->users()->select('login_user_id')->where('tenant_id', $id)->whereNotNull('login_user_id'))))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString());

        $linked = IdentityLookup::run(fn () => $this->users()
            ->whereIn('login_user_id', $page->getCollection()->pluck('id'))
            ->get(['id', 'tenant_id', 'login_user_id', 'is_active'])
            ->groupBy('login_user_id'));

        return $page->through(function (User $user) use ($linked) {
            $rows = $linked[$user->id] ?? collect();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'is_active' => $user->is_active,
                'home' => $user->tenant_id,
                'on' => $rows->where('is_active', true)->pluck('tenant_id')->values()->all(),
                'off' => $rows->where('is_active', false)->pluck('tenant_id')->values()->all(),
            ];
        });
    }

    /** A main account of a customer company, or null. */
    public function main(int $userId): ?User
    {
        $user = IdentityLookup::run(fn () => $this->users()->whereKey($userId)->whereNull('login_user_id')->whereNull('customer_id')->first());

        return $user !== null && Tenant::whereKey($user->tenant_id)->where('is_platform', false)->exists() ? $user : null;
    }

    /** The person's row in a company, active or not, or null. */
    public function linkedIn(User $main, int $tenantId): ?User
    {
        return IdentityLookup::run(fn () => $this->users()->where('login_user_id', $main->id)->where('tenant_id', $tenantId)->first());
    }

    /** Whether an e-mail is used by any user (any company). */
    public function emailTaken(string $email): bool
    {
        return IdentityLookup::run(fn () => $this->users()->withTrashed()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists());
    }

    /**
     * @return Builder<User>
     */
    private function users(): Builder
    {
        // The platform's view across companies: superadmin manages who works where.
        return User::query()->withoutGlobalScope(TenantScope::class);
    }
}
