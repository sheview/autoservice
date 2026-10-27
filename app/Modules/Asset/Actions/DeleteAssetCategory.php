<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Events\AssetCategoryDeleted;
use App\Modules\Asset\Models\AssetCategory;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a category that no (non-deleted) asset uses. Parts in it stay, with no category.
 */
class DeleteAssetCategory
{
    public function handle(AssetCategory $category): void
    {
        if ($category->assets()->exists()) {
            throw ValidationException::withMessages(['category' => __('asset.categories.in_use')]);
        }

        $category->delete();
        AssetCategoryDeleted::dispatch($category->id);
    }
}
