<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;

/**
 * The asset categories a PM checklist or template assignment points at, for the Asset module.
 */
class CategoryIdsWithChecklists
{
    /**
     * @return list<int>
     */
    public function handle(): array
    {
        return PmChecklist::query()->whereNotNull('asset_category_id')->distinct()->pluck('asset_category_id')
            ->merge(PmTemplateAssignment::query()->whereNotNull('asset_category_id')->distinct()->pluck('asset_category_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
