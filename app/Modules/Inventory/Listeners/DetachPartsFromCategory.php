<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Asset\Events\AssetCategoryDeleted;
use App\Modules\Inventory\Models\Part;

/**
 * A category was removed (Asset module): its parts stay, with no category.
 */
class DetachPartsFromCategory
{
    public function handle(AssetCategoryDeleted $event): void
    {
        Part::withTrashed()->where('asset_category_id', $event->categoryId)->update(['asset_category_id' => null]);
    }
}
