<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Asset\Events\AssetCategoriesMerged;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PurchaseRequest;

/**
 * Two categories were merged (Asset module): parts and purchase requests of the old one move along.
 */
class MovePartsToMergedCategory
{
    public function handle(AssetCategoriesMerged $event): void
    {
        Part::withTrashed()->where('asset_category_id', $event->fromId)->update(['asset_category_id' => $event->intoId]);
        PurchaseRequest::withTrashed()->where('asset_category_id', $event->fromId)->update(['asset_category_id' => $event->intoId]);
    }
}
