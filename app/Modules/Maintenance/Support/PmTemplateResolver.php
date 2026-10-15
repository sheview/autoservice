<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use Illuminate\Support\Collection;

/**
 * Which PM template checks an asset: the newest published version of the code picked by the most
 * specific match, in this order:
 *
 *   1. the plan's own choice for the asset (pm_plan_assets.template_code)
 *   2. an assignment to the asset
 *   3. an assignment to its model
 *   4. an assignment to its brand within its category
 *   5. an assignment to its category
 *   6. the default assignment (nothing filled in)
 *
 * A code with no published version is passed over. Ties go to the oldest assignment. Everything is
 * loaded once, so a work order of hundreds of assets costs three queries.
 */
final class PmTemplateResolver
{
    /**
     * @param  Collection<string, PmChecklist>  $templates  newest published version by code
     * @param  Collection<int, PmTemplateAssignment>  $assignments
     * @param  array<int, string>  $overrides  template code by asset id
     */
    private function __construct(
        private Collection $templates,
        private Collection $assignments,
        private array $overrides,
    ) {}

    public static function load(?int $planId = null): self
    {
        $templates = PmChecklist::query()
            ->where('status', PmChecklist::STATUS_PUBLISHED)
            ->orderByDesc('version')
            ->get()
            ->unique('code')
            ->keyBy('code');

        $assignments = PmTemplateAssignment::query()
            ->whereIn('template_code', $templates->keys())
            ->orderBy('id')
            ->get();

        $overrides = $planId === null ? [] : PmPlanAsset::query()
            ->where('pm_plan_id', $planId)
            ->whereNotNull('template_code')
            ->pluck('template_code', 'asset_id')
            ->all();

        return new self($templates, $assignments, $overrides);
    }

    /**
     * @param  array{id: int, category_id?: int|null, asset_brand_id?: int|null, asset_model_id?: int|null}  $asset
     */
    public function for(array $asset): ?PmChecklist
    {
        $override = $this->overrides[$asset['id']] ?? null;
        if ($override !== null && $this->templates->has($override)) {
            return $this->templates[$override];
        }

        $categoryId = $asset['category_id'] ?? null;
        $brandId = $asset['asset_brand_id'] ?? null;
        $modelId = $asset['asset_model_id'] ?? null;

        $levels = [
            fn (PmTemplateAssignment $a) => (int) $a->asset_id === (int) $asset['id'],
            fn (PmTemplateAssignment $a) => $modelId !== null && $a->asset_id === null && (int) $a->asset_model_id === (int) $modelId,
            fn (PmTemplateAssignment $a) => $brandId !== null && $categoryId !== null && $a->asset_id === null && $a->asset_model_id === null
                && (int) $a->asset_brand_id === (int) $brandId && (int) $a->asset_category_id === (int) $categoryId,
            fn (PmTemplateAssignment $a) => $categoryId !== null && $a->asset_id === null && $a->asset_model_id === null && $a->asset_brand_id === null
                && (int) $a->asset_category_id === (int) $categoryId,
            fn (PmTemplateAssignment $a) => $a->asset_id === null && $a->asset_model_id === null && $a->asset_brand_id === null && $a->asset_category_id === null,
        ];

        foreach ($levels as $matches) {
            $assignment = $this->assignments->first($matches);
            if ($assignment !== null) {
                return $this->templates[$assignment->template_code];
            }
        }

        return null;
    }

    /**
     * The columns a work order item keeps of the template it was given.
     *
     * @return array{pm_checklist_id: int|null, template_code: string|null, template_version: int|null, checklist: list<array<string, mixed>>}
     */
    public static function itemColumns(?PmChecklist $template): array
    {
        return [
            'pm_checklist_id' => $template?->id,
            'template_code' => $template?->code,
            'template_version' => $template?->version,
            'checklist' => $template?->items ?? [],
        ];
    }
}
