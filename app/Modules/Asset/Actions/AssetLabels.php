<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Code and name of assets by id, for other modules linking to them (what a purchase receipt was
 * registered as). Deleted assets are named too.
 */
class AssetLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, asset_code: string, name: string}> keyed by id
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Asset::withTrashed()->whereKey($ids)->get(['id', 'ulid', 'asset_code', 'name'])
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => $asset->only(['ulid', 'asset_code', 'name'])])
            ->all();
    }
}
