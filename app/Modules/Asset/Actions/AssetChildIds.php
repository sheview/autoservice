<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * The parts of assets (parent_asset_id: a monitor of a PC), for other modules — e.g. a PM work order
 * checks a PC with its monitor as one set. One level only (a part has no parts). Deleted parts are
 * left out.
 */
class AssetChildIds
{
    /**
     * @param  list<int>  $parentIds
     * @return list<int> the parts' ids, by asset code
     */
    public function handle(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        return Asset::query()
            ->whereIn('parent_asset_id', $parentIds)
            ->orderBy('asset_code')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
