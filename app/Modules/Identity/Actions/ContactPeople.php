<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Active people who may report a problem, for other modules (e.g. the person reporting on a ticket):
 * the accounts of a customer, or the tenant's own staff when there is no customer.
 */
class ContactPeople
{
    /**
     * @return list<array{id: int, name: string, phone: string|null, position: string|null}>
     */
    public function handle(?int $customerId): array
    {
        return User::where('is_active', true)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId), fn ($q) => $q->whereNull('customer_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'position'])
            ->map(fn (User $u) => $u->only(['id', 'name', 'phone', 'position']))
            ->values()
            ->all();
    }
}
