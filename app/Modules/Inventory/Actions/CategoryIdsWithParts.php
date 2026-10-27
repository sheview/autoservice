<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * The asset categories (shared with parts) that hold at least one part, for the Asset module.
 */
class CategoryIdsWithParts
{
    /**
     * @return list<int>
     */
    public function handle(): array
    {
        return Part::query()->whereNotNull('asset_category_id')->distinct()->pluck('asset_category_id')->map(fn ($id) => (int) $id)->all();
    }
}
