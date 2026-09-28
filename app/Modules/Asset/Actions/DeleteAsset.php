<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Soft-deletes an asset. The asset code stays taken, so a printed label never points to another asset.
 */
class DeleteAsset
{
    public function handle(Asset $asset): void
    {
        $asset->delete();
    }
}
