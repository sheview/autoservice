<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches a site photo to a piece of equipment while the work is going on (at most MAX_PHOTOS
 * each), optionally of one checklist question (its key, kept as the "question" property) and
 * with a caption; both are printed with the photo in the report.
 */
class AddPmPhoto
{
    public function handle(PmVisitItem $item, UploadedFile $file, ?string $question = null, ?string $caption = null): Media
    {
        if ($question !== null && ! in_array($question, array_column($item->checklist ?? [], 'key'), true)) {
            throw ValidationException::withMessages(['photo' => __('maintenance.photos.unknown_question')]);
        }
        if (! $item->visit->isWorking()) {
            throw ValidationException::withMessages(['photo' => __('maintenance.visits.not_in_progress')]);
        }
        if ($item->getMedia(PmVisitItem::PHOTOS)->count() >= PmVisitItem::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photo' => __('maintenance.photos.too_many', ['max' => PmVisitItem::MAX_PHOTOS])]);
        }

        return $item->addMedia($file)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->withCustomProperties(array_filter(['question' => $question, 'caption' => filled($caption) ? trim($caption) : null]))
            ->toMediaCollection(PmVisitItem::PHOTOS);
    }
}
