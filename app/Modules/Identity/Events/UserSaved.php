<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user of the current company was added or changed (role, active, ...) through SaveUser.
 */
class UserSaved
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
