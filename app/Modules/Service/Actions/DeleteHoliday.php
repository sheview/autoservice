<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Holiday;

/**
 * Removes a day off. Holidays are calendar settings, not business records, so they are deleted for real.
 */
class DeleteHoliday
{
    public function handle(Holiday $holiday): void
    {
        $holiday->delete();
    }
}
