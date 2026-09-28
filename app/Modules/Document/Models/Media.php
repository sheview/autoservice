<?php

namespace App\Modules\Document\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * An uploaded file of a tenant (spatie/laravel-medialibrary, config media-library.media_model).
 */
class Media extends SpatieMedia
{
    use BelongsToTenant;
}
