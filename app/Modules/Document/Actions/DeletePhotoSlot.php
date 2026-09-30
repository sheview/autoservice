<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Support\PhotoSlots;
use Spatie\MediaLibrary\HasMedia;

/**
 * Removes the photo of a slot of a record (nothing happens when the slot is empty).
 */
class DeletePhotoSlot
{
    public function handle(HasMedia $model, int $slot): void
    {
        PhotoSlots::find($model, $slot)?->delete();
    }
}
