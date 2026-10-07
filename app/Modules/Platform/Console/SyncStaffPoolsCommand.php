<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\SyncStaffPool;
use App\Modules\Platform\Models\StaffPool;
use Illuminate\Console\Command;

/**
 * Every hour: the linked accounts of every staff pool follow the companies' people (a role given
 * or a person deactivated without going through the user form, e.g. by an import).
 */
class SyncStaffPoolsCommand extends Command
{
    protected $signature = 'platform:sync-staff-pools';

    protected $description = 'Bring the linked accounts of the staff shared between companies up to date';

    public function handle(SyncStaffPool $sync): int
    {
        StaffPool::query()->each(fn (StaffPool $pool) => $sync->handle($pool));

        return self::SUCCESS;
    }
}
