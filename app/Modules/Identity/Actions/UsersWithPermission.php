<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Active users of the current tenant who hold a permission through one of their roles,
 * e.g. who to notify about something (for other modules, which do not use User directly).
 */
class UsersWithPermission
{
    /**
     * @return Collection<int, User>
     */
    public function handle(string $permission): Collection
    {
        return User::permission($permission)->where('is_active', true)->get();
    }
}
