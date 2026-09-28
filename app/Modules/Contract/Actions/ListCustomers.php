<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;

/**
 * Customers of the current tenant as plain arrays, for other modules (asset form, filters,
 * Excel import/export) that must not use the Customer model directly.
 */
class ListCustomers
{
    /**
     * @param  bool  $withTrashed  include deleted customers (to show names on old records)
     * @return list<array{id: int, code: string, name: string}>
     */
    public function handle(bool $withTrashed = false): array
    {
        return Customer::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Customer $customer) => $customer->only(['id', 'code', 'name']))
            ->all();
    }
}
