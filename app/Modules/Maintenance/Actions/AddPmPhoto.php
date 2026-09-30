<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches a site photo to an asset of a running round (at most MAX_PHOTOS per asset).
 */
class AddPmPhoto
{
    public function handle(PmVisitItem $item, UploadedFile $file): Media
    {
        if ($item->visit->status !== PmVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['photo' => __('maintenance.visits.not_in_progress')]);
        }
        if ($item->getMedia(PmVisitItem::PHOTOS)->count() >= PmVisitItem::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photo' => __('maintenance.photos.too_many', ['max' => PmVisitItem::MAX_PHOTOS])]);
        }

        return $item->addMedia($file)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection(PmVisitItem::PHOTOS);
    }
}
