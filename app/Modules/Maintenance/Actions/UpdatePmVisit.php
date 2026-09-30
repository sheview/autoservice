<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Validation\ValidationException;

/**
 * Sets the appointment date and the technician of a round that is still to do. A change re-arms
 * the reminder e-mail (NotifyUpcomingPm).
 */
class UpdatePmVisit
{
    /**
     * @param  array{scheduled_on?: string|null, assignee_id?: int|null}  $data  validated
     */
    public function handle(PmVisit $visit, array $data): PmVisit
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_open')]);
        }

        $visit->fill([
            'scheduled_on' => $data['scheduled_on'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
        ]);
        // A new date or technician gets its own reminder.
        if ($visit->isDirty(['scheduled_on', 'assignee_id'])) {
            $visit->reminded_at = null;
        }
        $visit->save();

        return $visit;
    }
}
