<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlan;

/**
 * The daily run in the current company: every active plan still running gets the work orders of the
 * rounds that have come near (SchedulePmVisits), none twice.
 */
class GeneratePmRounds
{
    public function __construct(private SchedulePmVisits $schedule) {}

    /**
     * @return int how many work orders were made
     */
    public function handle(): int
    {
        $made = 0;
        PmPlan::query()
            ->where('active', true)
            ->where('ends_on', '>=', today()->toDateString())
            ->orderBy('id')
            ->each(function (PmPlan $plan) use (&$made) {
                $made += $this->schedule->handle($plan);
            });

        return $made;
    }
}
