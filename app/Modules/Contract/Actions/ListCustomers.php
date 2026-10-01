<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;

/**
 * Customers of the current tenant as plain arrays, for other modules (asset form, filters,
 * Excel import/export) that must not use the Customer model directly.
 */
class ListCustomers
{
    /**
     * @param  bool  $withTrashed  include deleted customers (to show names on old records)
     * @param  User|null  $user  only the customers this user reaches with $permission (pickers and
     *                           filters); null = every customer (names for records already shown)
     * @param  string  $permission  a customers.* or contracts.* permission: both reach the same customers (ContractScope)
     * @return list<array{id: int, code: string, name: string}>
     */
    public function handle(bool $withTrashed = false, ?User $user = null, string $permission = 'customers.view'): array
    {
        return Customer::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($user, fn ($q, User $user) => ContractScope::customers($q, $user, $permission))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Customer $customer) => $customer->only(['id', 'code', 'name']))
            ->all();
    }
}
