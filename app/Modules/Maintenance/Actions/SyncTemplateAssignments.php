<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmTemplateAssignment;

/**
 * Sets which assets a template (by code, so every version of it) checks: categories, brands
 * within a category, models. A row with nothing filled in makes it the default template.
 * Assignments to single assets (set from a plan) are left alone.
 */
class SyncTemplateAssignments
{
    /**
     * @param  list<array{asset_category_id?: int|null, asset_brand_id?: int|null, asset_model_id?: int|null}>  $assignments  validated
     */
    public function handle(string $templateCode, array $assignments): void
    {
        PmTemplateAssignment::query()->where('template_code', $templateCode)->whereNull('asset_id')->delete();

        $seen = [];
        foreach ($assignments as $assignment) {
            $row = [
                'asset_category_id' => $assignment['asset_category_id'] ?? null,
                'asset_brand_id' => $assignment['asset_brand_id'] ?? null,
                'asset_model_id' => $assignment['asset_model_id'] ?? null,
            ];
            $key = implode('|', $row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            PmTemplateAssignment::create(['template_code' => $templateCode, ...$row]);
        }
    }
}
