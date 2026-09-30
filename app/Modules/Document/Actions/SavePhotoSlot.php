<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Support\PhotoSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Puts an uploaded photo in a slot of a record, replacing the photo that was there.
 */
class SavePhotoSlot
{
    public function handle(HasMedia $model, int $slot, UploadedFile $file): Media
    {
        return DB::transaction(function () use ($model, $slot, $file) {
            PhotoSlots::find($model, $slot)?->delete();

            return $model->addMedia($file)
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['slot' => $slot])
                ->toMediaCollection(PhotoSlots::COLLECTION);
        });
    }
}
