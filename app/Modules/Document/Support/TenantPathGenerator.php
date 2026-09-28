<?php

namespace App\Modules\Document\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Stores media as "tenants/{tenant_id}/{media id}/..." so the files of each tenant are in
 * their own folder (easy to back up, move or delete one tenant).
 */
class TenantPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        return 'tenants/'.$media->getAttribute('tenant_id').'/'.parent::getBasePath($media);
    }
}
