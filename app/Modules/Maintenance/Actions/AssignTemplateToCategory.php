<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmTemplateAssignment;

/**
 * Ties a template to one asset category (null = the default template), replacing the category it
 * was tied to before. Assignments to a brand, model or asset are left alone.
 */
class AssignTemplateToCategory
{
    public function handle(string $templateCode, ?int $categoryId): void
    {
        PmTemplateAssignment::query()
            ->where('template_code', $templateCode)
            ->whereNull('asset_id')->whereNull('asset_model_id')->whereNull('asset_brand_id')
            ->delete();

        PmTemplateAssignment::create(['template_code' => $templateCode, 'asset_category_id' => $categoryId]);
    }
}
