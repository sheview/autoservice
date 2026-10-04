<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\CustomerSite;

/**
 * Sites of customers as plain arrays, for other modules (IP management) that must not use the
 * CustomerSite model directly.
 */
class ListSites
{
    /**
     * @param  int|null  $customerId  only this customer's sites; null = every site
     * @param  bool  $withTrashed  include deleted sites (to show names on old records)
     * @return list<array{id: int, customer_id: int, name: string, address: string|null}>
     */
    public function handle(?int $customerId = null, bool $withTrashed = false): array
    {
        return CustomerSite::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->orderBy('name')
            ->get(['id', 'customer_id', 'name', 'address'])
            ->map(fn (CustomerSite $site) => $site->only(['id', 'customer_id', 'name', 'address']))
            ->all();
    }
}
