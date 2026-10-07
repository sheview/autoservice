<?php

namespace App\Modules\Platform\Listeners;

use App\Modules\Identity\Events\UserSaved;
use App\Modules\Platform\Actions\SyncStaffPool;
use App\Modules\Platform\Models\StaffPool;

/**
 * A person added or changed in a company that shares its staff: their linked accounts in the
 * other companies follow at once (a new technician can be assigned there straight away).
 */
class SyncStaffPoolsForUser
{
    public function __construct(private SyncStaffPool $sync) {}

    public function handle(UserSaved $event): void
    {
        if ($event->user->login_user_id !== null) {
            return;
        }

        StaffPool::where('from_tenant_id', $event->user->tenant_id)->get()
            ->each(fn (StaffPool $pool) => $this->sync->handle($pool, $event->user->id));
    }
}
