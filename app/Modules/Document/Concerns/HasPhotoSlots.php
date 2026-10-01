<?php

namespace App\Modules\Document\Concerns;

use App\Modules\Document\Support\PhotoSlots;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * For a model (which must implement Spatie\MediaLibrary\HasMedia) that carries up to
 * PhotoSlots::COUNT photos: a main one and extras. See PhotoSlots. A model with more collections
 * (e.g. HasAttachments) writes its own registerMediaCollections() and calls registerPhotoCollection().
 */
trait HasPhotoSlots
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->registerPhotoCollection();
    }

    protected function registerPhotoCollection(): void
    {
        $this->addMediaCollection(PhotoSlots::COLLECTION)->acceptsMimeTypes(PhotoSlots::MIME_TYPES);
    }
}
