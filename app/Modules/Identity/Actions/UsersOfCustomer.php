<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Active customer accounts of a customer (Contract module), for other modules.
 */
class UsersOfCustomer
{
    /**
     * @return Collection<int, User>
     */
    public function handle(int $customerId): Collection
    {
        return User::where('customer_id', $customerId)->where('is_active', true)->get();
    }
}
