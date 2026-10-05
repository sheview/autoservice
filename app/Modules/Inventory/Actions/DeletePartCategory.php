<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCategory;
use Illuminate\Support\Facades\DB;

/**
 * Removes a part category; its parts stay, with no category.
 */
class DeletePartCategory
{
    public function handle(PartCategory $category): void
    {
        DB::transaction(function () use ($category) {
            Part::withTrashed()->where('part_category_id', $category->id)->update(['part_category_id' => null]);
            $category->delete();
        });
    }
}
