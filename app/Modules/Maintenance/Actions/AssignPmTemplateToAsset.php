<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;

/**
 * "Use this form for this asset from now on": the assignment to the asset itself (level 2 of
 * PmTemplateResolver), so later work orders and rounds of every plan get it. A plan that names its own
 * form for the asset (level 1, from its import) is told the same, or it would still win.
 */
class AssignPmTemplateToAsset
{
    public function handle(int $assetId, string $templateCode): void
    {
        $assignment = PmTemplateAssignment::query()->where('asset_id', $assetId)->orderBy('id')->first();
        if ($assignment !== null) {
            $assignment->update(['template_code' => $templateCode]);
            // The resolver takes the oldest; any other row for the asset would only confuse it.
            PmTemplateAssignment::query()->where('asset_id', $assetId)->whereKeyNot($assignment->id)->delete();
        } else {
            PmTemplateAssignment::create(['template_code' => $templateCode, 'asset_id' => $assetId]);
        }

        PmPlanAsset::query()->where('asset_id', $assetId)->whereNotNull('template_code')->update(['template_code' => $templateCode]);
    }
}
