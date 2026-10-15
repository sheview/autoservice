<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetBrand;
use App\Modules\Asset\Models\AssetModel;

/**
 * The brand and model catalog of the current tenant, for choosing in other modules (PM templates).
 */
class CatalogOptions
{
    /**
     * @return array{brands: list<array{id: int, name: string}>, models: list<array{id: int, name: string, asset_brand_id: int, asset_category_id: int|null}>}
     */
    public function handle(): array
    {
        return [
            'brands' => AssetBrand::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'models' => AssetModel::query()->orderBy('name')->get(['id', 'name', 'asset_brand_id', 'asset_category_id'])->toArray(),
        ];
    }
}
