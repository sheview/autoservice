<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Holiday;

/**
 * Adds a day off. Due times of tickets already opened are not moved (they were promised with
 * the calendar of that moment); new tickets and tickets that come back from hold use the new one.
 */
class SaveHoliday
{
    /**
     * @param  array{date: string, name: string}  $data
     */
    public function handle(array $data): Holiday
    {
        return Holiday::create(['date' => $data['date'], 'name' => trim($data['name'])]);
    }
}
