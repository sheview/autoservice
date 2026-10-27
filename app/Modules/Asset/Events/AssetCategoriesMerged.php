<?php

namespace App\Modules\Asset\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Category $fromId was merged into $intoId (MergeAssetCategories): what other modules keep in
 * the old one (parts, purchase requests, PM checklists) moves to the other, in the same transaction.
 */
class AssetCategoriesMerged
{
    use Dispatchable;

    public function __construct(public int $fromId, public int $intoId) {}
}
