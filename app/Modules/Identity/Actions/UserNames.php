<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Names of users of the current tenant by id, for other modules (e.g. assignees in a list).
 * Users of other tenants are not readable here and are left out.
 */
class UserNames
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string> id => name
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();
    }
}
