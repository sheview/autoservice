<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Models\StaffPool;

/**
 * Ends a staff pool: its people's accounts in the other company are switched off (kept, with the
 * work done and assigned there). Pools are settings, not business records, so the row goes.
 */
class DeleteStaffPool
{
    public function __construct(private SyncStaffPool $sync) {}

    public function handle(StaffPool $pool): void
    {
        $pool->is_active = false;
        $this->sync->handle($pool);
        $pool->delete();
    }
}
