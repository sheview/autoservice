<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The technician's "arrived on site": a scheduled work order starts (StartPmVisit), and the time is
 * kept once — tapping again does not move it.
 */
class MarkPmVisitArrival
{
    public function __construct(private StartPmVisit $start, private LogPmEvent $logEvent) {}

    public function handle(PmVisit $visit, User $actor): PmVisit
    {
        if ($visit->status === PmVisit::STATUS_SCHEDULED) {
            $visit = $this->start->handle($visit, $actor);
        }

        return DB::transaction(function () use ($visit, $actor) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! $visit->isWorking()) {
                throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
            }
            if ($visit->onsite_at === null) {
                $visit->update(['onsite_at' => now()]);
                $this->logEvent->handle($visit, PmVisitEvent::TYPE_ARRIVED, $actor);
            }

            return $visit;
        });
    }
}
