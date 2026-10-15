<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\CategoryIdsByPrefix;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\StandardTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Adds the standard PM templates (StandardTemplates) the current company does not have yet: those
 * of its categories with a standard code prefix, and the default template. Each is published and
 * tied to its categories, unless a category already has a template of the company's own: a template
 * the company made or changed is never replaced, the standard one is then only added to choose from.
 */
class AddStandardChecklists
{
    public function __construct(private CategoryIdsByPrefix $categoryIds) {}

    /**
     * @return int how many were added
     */
    public function handle(): int
    {
        return DB::transaction(function () {
            $categories = $this->categoryIds->handle();
            $missing = $this->missing();
            $taken = PmTemplateAssignment::query()
                ->whereNull('asset_id')->whereNull('asset_model_id')->whereNull('asset_brand_id')
                ->pluck('asset_category_id')->map(fn ($id) => (int) $id)->all(); // 0 = the default

            foreach ($missing as $standard) {
                PmChecklist::create([
                    'code' => $standard['code'],
                    'name' => $standard['name'],
                    'service_line' => $standard['service_line'],
                    'layout' => $standard['layout'],
                    'blocks' => $standard['blocks'],
                    'items' => ChecklistItems::clean($standard['items']),
                    'version' => 1,
                    'status' => PmChecklist::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]);

                foreach ($standard['prefixes'] as $prefix) {
                    $categoryId = $prefix === '' ? 0 : ($categories[$prefix] ?? null);
                    if ($categoryId === null || in_array($categoryId, $taken, true)) {
                        continue;
                    }
                    PmTemplateAssignment::create(['template_code' => $standard['code'], 'asset_category_id' => $categoryId ?: null]);
                    $taken[] = $categoryId;
                }
            }

            return count($missing);
        });
    }

    /**
     * Standard templates whose code the company does not have, of categories it has.
     *
     * @return list<array<string, mixed>> see StandardTemplates::all()
     */
    public function missing(): array
    {
        $categories = $this->categoryIds->handle();
        $codes = PmChecklist::query()->distinct()->pluck('code')->all();

        return array_values(array_filter(StandardTemplates::all(), fn (array $standard) => ! in_array($standard['code'], $codes, true)
            && collect($standard['needs'])->contains(fn (string $prefix) => $prefix === '' || isset($categories[$prefix]))));
    }
}
