<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Holiday;
use Carbon\CarbonInterface;

/**
 * The company's days off between two dates, for other modules (a room's calendar).
 */
class HolidaysBetween
{
    /**
     * @return array<string, string> Y-m-d => name
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        return Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get()
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->name])
            ->all();
    }
}
