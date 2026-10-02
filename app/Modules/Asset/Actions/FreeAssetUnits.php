<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * How much of each asset is free to hand out now: spare, less what issue/loan requests hold. For
 * other modules offering assets to hand out (what a purchase brought, Inventory module).
 */
class FreeAssetUnits
{
    public function __construct(private AssetHeldQuantities $held) {}

    /**
     * @param  list<int>  $assetIds
     * @return array<int, int> asset id => free quantity (0 when none)
     */
    public function handle(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter($assetIds)));
        if ($assetIds === []) {
            return [];
        }
        $held = $this->held->handle($assetIds);

        return Asset::query()->whereKey($assetIds)->get(['id', 'status', 'quantity'])
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => $asset->status === Asset::STATUS_SPARE
                ? max(0, (int) $asset->quantity - ($held[$asset->id] ?? 0))
                : 0])
            ->all();
    }
}
