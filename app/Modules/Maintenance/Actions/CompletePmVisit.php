<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Validation\ValidationException;

/**
 * Closes a running round once every asset has a result.
 */
class CompletePmVisit
{
    public function handle(PmVisit $visit, ?string $summary): PmVisit
    {
        if ($visit->status !== PmVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        $pending = $visit->items()->where('result', PmVisitItem::RESULT_PENDING)->count();
        if ($pending > 0) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.items_pending', ['count' => $pending])]);
        }

        $visit->update([
            'status' => PmVisit::STATUS_COMPLETED,
            'completed_at' => now(),
            'summary' => $summary,
        ]);

        return $visit;
    }
}
