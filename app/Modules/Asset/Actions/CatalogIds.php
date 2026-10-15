<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetBrand;
use App\Modules\Asset\Models\AssetModel;

/**
 * The catalog ids of a brand and model typed on an asset, adding them to the catalog when new.
 * Names match without case (the collation), so "DELL" finds "Dell".
 */
class CatalogIds
{
    /**
     * @return array{asset_brand_id: int|null, asset_model_id: int|null}
     */
    public function handle(?string $brand, ?string $model, ?int $categoryId): array
    {
        $brand = mb_substr(trim((string) $brand), 0, 100);
        if ($brand === '') {
            return ['asset_brand_id' => null, 'asset_model_id' => null];
        }
        $brandId = AssetBrand::query()->firstOrCreate(['name' => $brand])->id;

        $model = mb_substr(trim((string) $model), 0, 150);
        $modelId = $model === '' ? null : AssetModel::query()->firstOrCreate(
            ['asset_brand_id' => $brandId, 'name' => $model],
            ['asset_category_id' => $categoryId],
        )->id;

        return ['asset_brand_id' => $brandId, 'asset_model_id' => $modelId];
    }
}
