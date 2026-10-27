<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Changes only where a device is — its branch and/or location — e.g. from its QR page on site.
 * A location named as a storage location is kept there too (StorageLocationOptions::idFor).
 * The asset's activity log keeps the move with who made it.
 */
class MoveAsset
{
    public function __construct(private StorageLocationOptions $locations) {}

    /**
     * @param  array{branch_id?: int|null, location?: string|null}  $data  validated
     */
    public function handle(Asset $asset, array $data): Asset
    {
        $data = array_intersect_key($data, array_flip(['branch_id', 'location']));
        if (array_key_exists('location', $data)) {
            $data['storage_location_id'] = $this->locations->idFor($data['location']);
        }
        $asset->fill($data)->save();

        return $asset;
    }
}
