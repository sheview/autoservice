<?php

namespace App\Modules\Asset\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An asset category was removed: parts in it (Inventory module) are left with no category.
 */
class AssetCategoryDeleted
{
    use Dispatchable;

    public function __construct(public int $categoryId) {}
}
