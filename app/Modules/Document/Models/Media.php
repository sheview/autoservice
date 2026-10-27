<?php

namespace App\Modules\Document\Models;

use App\Modules\Document\Actions\PhotoThumbnail;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * An uploaded file of a tenant (spatie/laravel-medialibrary, config media-library.media_model).
 */
class Media extends SpatieMedia
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        // The small copy of a photo for the lists is not a file the media library knows.
        static::deleted(fn (Media $media) => app(PhotoThumbnail::class)->forget($media));
    }
}
