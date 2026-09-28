<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Active staff of the current tenant who hold a permission through one of their roles,
 * e.g. who to notify or who can be given a job (for other modules, which do not use User directly).
 * Customer accounts are never included: they are not staff, whatever their role allows.
 */
class UsersWithPermission
{
    /**
     * @return Collection<int, User>
     */
    public function handle(string $permission): Collection
    {
        return User::permission($permission)->where('is_active', true)->whereNull('customer_id')->get();
    }
}
