<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;

/**
 * How many of each asset are held by issue/loan forms that are asked for or out. The rest of the
 * asset's quantity is available; a returned, rejected or cancelled form holds nothing, so its
 * quantity is available again without anything to update.
 */
class CheckedOutQuantities
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, int> asset id => quantity held (assets with nothing held are left out)
     */
    public function handle(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return AssetCheckout::query()
            ->whereIn('asset_id', $assetIds)
            ->whereIn('status', AssetCheckout::OPEN_STATUSES)
            ->groupBy('asset_id')
            ->selectRaw('asset_id, sum(quantity) as held')
            ->pluck('held', 'asset_id')
            ->map(fn ($held) => (int) $held)
            ->all();
    }
}
