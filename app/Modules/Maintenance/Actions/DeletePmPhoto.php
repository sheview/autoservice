<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Removes a site photo while the round is still running; a closed round keeps its record.
 */
class DeletePmPhoto
{
    public function handle(PmVisitItem $item, Media $photo): void
    {
        if ($item->visit->status !== PmVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['photo' => __('maintenance.visits.not_in_progress')]);
        }

        $photo->delete();
    }
}
