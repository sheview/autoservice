<?php

namespace App\Modules\Document\Concerns;

use App\Modules\Document\Support\PhotoSlots;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * For a model (which must implement Spatie\MediaLibrary\HasMedia) that carries up to
 * PhotoSlots::COUNT photos: a main one and extras. See PhotoSlots.
 */
trait HasPhotoSlots
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(PhotoSlots::COLLECTION)->acceptsMimeTypes(PhotoSlots::MIME_TYPES);
    }
}
