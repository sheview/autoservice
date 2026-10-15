<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The technician's "left the site", after arriving. Tapping again moves it to now (they came back
 * and left later).
 */
class MarkPmVisitDeparture
{
    public function __construct(private LogPmEvent $logEvent) {}

    public function handle(PmVisit $visit, User $actor): PmVisit
    {
        return DB::transaction(function () use ($visit, $actor) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! $visit->isWorking()) {
                throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
            }
            if ($visit->onsite_at === null) {
                throw ValidationException::withMessages(['visit' => __('maintenance.field.not_arrived')]);
            }

            $visit->update(['departed_at' => now()]);
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_DEPARTED, $actor);

            return $visit;
        });
    }
}
