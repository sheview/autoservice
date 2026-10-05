<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Changes only where a device is — its branch and/or location — e.g. from its QR page on site.
 * The asset's activity log keeps the move with who made it.
 */
class MoveAsset
{
    /**
     * @param  array{branch_id?: int|null, location?: string|null}  $data  validated
     */
    public function handle(Asset $asset, array $data): Asset
    {
        $asset->fill(array_intersect_key($data, array_flip(['branch_id', 'location'])))->save();

        return $asset;
    }
}
