<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Events\AssetCategoriesMerged;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges a category that repeats another (e.g. "Notebook" and "โน้ตบุ๊ก") into it: every asset,
 * catalog model, part, purchase request and PM checklist of $from moves to $into, then $from is
 * removed (soft delete). Assets keep their codes; their spec values stay on them and show where
 * $into has a field of the same key. All or nothing.
 */
class MergeAssetCategories
{
    /**
     * @return int how many assets moved
     */
    public function handle(AssetCategory $from, AssetCategory $into): int
    {
        if ($from->is($into)) {
            throw ValidationException::withMessages(['into_id' => __('asset.categories.merge_same')]);
        }

        return DB::transaction(function () use ($from, $into) {
            $moved = Asset::withTrashed()->where('category_id', $from->id)->update(['category_id' => $into->id]);
            AssetModel::withTrashed()->where('asset_category_id', $from->id)->update(['asset_category_id' => $into->id]);
            AssetCategoriesMerged::dispatch($from->id, $into->id);
            $from->delete();

            return $moved;
        });
    }
}
