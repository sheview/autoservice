<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Active users of the current tenant by id (e.g. the recipients chosen for a notification).
 */
class FindUsers
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function handle(array $ids): Collection
    {
        return User::whereKey(array_values(array_unique($ids)))->where('is_active', true)->get();
    }
}
