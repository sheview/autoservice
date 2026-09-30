<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Validation\ValidationException;

/**
 * Sets the appointment date and the technician of a round that is still to do.
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

        $visit->update([
            'scheduled_on' => $data['scheduled_on'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
        ]);

        return $visit;
    }
}
