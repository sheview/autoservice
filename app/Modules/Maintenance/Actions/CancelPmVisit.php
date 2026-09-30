<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a round that is still to do; the reason is kept as its summary.
 */
class CancelPmVisit
{
    public function handle(PmVisit $visit, string $reason): PmVisit
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_open')]);
        }

        $visit->update([
            'status' => PmVisit::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'summary' => $reason,
        ]);

        return $visit;
    }
}
