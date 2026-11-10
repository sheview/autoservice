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
 * (match() also says which: plan, asset, model, brand, category, default.) A template picked by hand on
 * a work order is given directly and does not go through here.
 *
 * A code with no published version is passed over. Ties go to the oldest assignment. Everything is
 * loaded once, so a work order of hundreds of assets costs three queries.
 */
final class PmTemplateResolver
{
    /** How many questions an option shows before it is picked. */
    public const PREVIEW = 5;

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
        return $this->match($asset)['template'] ?? null;
    }

    /**
     * The template of an asset and the level that picked it (SOURCES), so a page can say why.
     *
     * @param  array{id: int, category_id?: int|null, asset_brand_id?: int|null, asset_model_id?: int|null}  $asset
     * @return array{template: PmChecklist, source: string}|null
     */
    public function match(array $asset): ?array
    {
        $override = $this->overrides[$asset['id']] ?? null;
        if ($override !== null && $this->templates->has($override)) {
            return ['template' => $this->templates[$override], 'source' => 'plan'];
        }

        foreach ($this->levels($asset) as $source => $matches) {
            $assignment = $this->assignments->first($matches);
            if ($assignment !== null) {
                return ['template' => $this->templates[$assignment->template_code], 'source' => $source];
            }
        }

        return null;
    }

    /**
     * The forms that fit an asset best, to offer first when one is picked by hand: the plan's choice,
     * every assignment that matches it (asset, model, brand, category, default), then the forms
     * assigned to anything else of its category. Most specific first, each code once.
     *
     * @param  array{id: int, category_id?: int|null, asset_brand_id?: int|null, asset_model_id?: int|null}  $asset
     * @return list<string>
     */
    public function suggested(array $asset): array
    {
        $codes = [];
        $override = $this->overrides[$asset['id']] ?? null;
        if ($override !== null && $this->templates->has($override)) {
            $codes[] = $override;
        }

        $categoryId = $asset['category_id'] ?? null;
        $levels = $this->levels($asset);
        $default = $levels['default'];
        unset($levels['default']);
        $levels['same_category'] = fn (PmTemplateAssignment $a) => $categoryId !== null && (int) $a->asset_category_id === (int) $categoryId;
        $levels['default'] = $default;

        foreach ($levels as $matches) {
            foreach ($this->assignments->filter($matches) as $assignment) {
                $codes[] = $assignment->template_code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Who an assignment is for, most specific first: whether it matches the asset, by level.
     *
     * @param  array{id: int, category_id?: int|null, asset_brand_id?: int|null, asset_model_id?: int|null}  $asset
     * @return array<string, \Closure(PmTemplateAssignment): bool>
     */
    private function levels(array $asset): array
    {
        $categoryId = $asset['category_id'] ?? null;
        $brandId = $asset['asset_brand_id'] ?? null;
        $modelId = $asset['asset_model_id'] ?? null;

        return [
            'asset' => fn (PmTemplateAssignment $a) => (int) $a->asset_id === (int) $asset['id'],
            'model' => fn (PmTemplateAssignment $a) => $modelId !== null && $a->asset_id === null && (int) $a->asset_model_id === (int) $modelId,
            'brand' => fn (PmTemplateAssignment $a) => $brandId !== null && $categoryId !== null && $a->asset_id === null && $a->asset_model_id === null
                && (int) $a->asset_brand_id === (int) $brandId && (int) $a->asset_category_id === (int) $categoryId,
            'category' => fn (PmTemplateAssignment $a) => $categoryId !== null && $a->asset_id === null && $a->asset_model_id === null && $a->asset_brand_id === null
                && (int) $a->asset_category_id === (int) $categoryId,
            'default' => fn (PmTemplateAssignment $a) => $a->asset_id === null && $a->asset_model_id === null && $a->asset_brand_id === null && $a->asset_category_id === null,
        ];
    }

    /**
     * The newest published version of a code; null when it has none.
     */
    public function template(string $code): ?PmChecklist
    {
        return $this->templates->get($code);
    }

    /**
     * Every template that may be picked by hand: newest published version, by name, with how many
     * questions it has and the first of them, so the right one is told apart before it is picked.
     *
     * @return list<array{code: string, name: string, questions: int, preview: list<string>}>
     */
    public function options(): array
    {
        return $this->templates->sortBy('name', SORT_NATURAL)
            ->map(fn (PmChecklist $template) => [
                'code' => $template->code,
                'name' => $template->name,
                'questions' => count($template->items ?? []),
                'preview' => collect($template->items ?? [])->pluck('label')->filter()->take(self::PREVIEW)->values()->all(),
            ])
            ->values()->all();
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
