<?php

namespace App\Modules\Labeling\Actions;

use App\Modules\Labeling\Models\AssetLabelPrint;

/**
 * When the labels of assets were last printed, and which assets have ever been printed.
 */
class LastLabelPrints
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, string> asset id => ISO time of the last print (assets never printed are left out)
     */
    public function handle(array $assetIds): array
    {
        return $assetIds === [] ? [] : AssetLabelPrint::query()
            ->whereIn('asset_id', $assetIds)
            ->groupBy('asset_id')
            ->selectRaw('asset_id, max(printed_at) as last_printed_at')
            ->pluck('last_printed_at', 'asset_id')
            ->map(fn ($at) => now()->parse($at)->toIso8601String())
            ->all();
    }

    /**
     * @return list<int>
     */
    public function printedAssetIds(): array
    {
        return AssetLabelPrint::query()->distinct()->pluck('asset_id')->all();
    }
}
