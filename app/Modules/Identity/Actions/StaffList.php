<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Active staff of the current tenant (not customer accounts), by name: whom another module can
 * pick, e.g. the team of a project (Contract module). With $ids: only those (active or not).
 */
class StaffList
{
    /**
     * @param  list<int>|null  $ids
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function handle(?array $ids = null): array
    {
        return User::query()
            ->whereNull('customer_id')
            ->when($ids === null, fn ($q) => $q->where('is_active', true), fn ($q) => $q->whereKey($ids))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();
    }
}
