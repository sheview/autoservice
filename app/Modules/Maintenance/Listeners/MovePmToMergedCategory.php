<?php

namespace App\Modules\Maintenance\Listeners;

use App\Modules\Asset\Events\AssetCategoriesMerged;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;

/**
 * Two categories were merged (Asset module): PM checklists and template assignments of the old one
 * move along, so its assets keep the same checks.
 */
class MovePmToMergedCategory
{
    public function handle(AssetCategoriesMerged $event): void
    {
        PmChecklist::withTrashed()->where('asset_category_id', $event->fromId)->update(['asset_category_id' => $event->intoId]);
        PmTemplateAssignment::query()->where('asset_category_id', $event->fromId)->update(['asset_category_id' => $event->intoId]);
    }
}
