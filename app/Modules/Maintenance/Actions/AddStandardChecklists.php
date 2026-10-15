<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\CategoryIdsByPrefix;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\StandardChecklists;
use Illuminate\Support\Facades\DB;

/**
 * Adds the standard PM checklists (StandardChecklists) the current company is missing: one for
 * each of its categories with a standard code prefix that has no checklist yet, and the general
 * checklist when it has none. A checklist the company made or changed is never touched.
 * Each is a template with the code STD-{prefix} (STD-GENERAL), tied to its category.
 */
class AddStandardChecklists
{
    public function __construct(private CategoryIdsByPrefix $categoryIds, private AssignTemplateToCategory $assign) {}

    /**
     * @return int how many were added
     */
    public function handle(): int
    {
        return DB::transaction(function () {
            $missing = $this->missing();
            foreach ($missing as $checklist) {
                $template = PmChecklist::create([
                    'code' => $checklist['code'],
                    'name' => $checklist['name'],
                    'asset_category_id' => $checklist['asset_category_id'],
                    'items' => ChecklistItems::clean($checklist['items']),
                    'published_at' => now(),
                ]);
                $this->assign->handle($template->code, $template->asset_category_id);
            }

            return count($missing);
        });
    }

    /**
     * @return list<array{code: string, name: string, asset_category_id: int|null, items: list<array<string, mixed>>}>
     */
    public function missing(): array
    {
        $categories = $this->categoryIds->handle();
        $taken = PmChecklist::query()->pluck('asset_category_id')->map(fn ($id) => $id === null ? 0 : (int) $id)->all();
        $missing = [];

        foreach (StandardChecklists::all() as $prefix => $checklist) {
            $categoryId = $prefix === '' ? null : ($categories[$prefix] ?? false);
            if ($categoryId === false || in_array($categoryId ?? 0, $taken, true)) {
                continue;
            }
            $missing[] = $checklist + ['code' => 'STD-'.($prefix === '' ? 'GENERAL' : $prefix), 'asset_category_id' => $categoryId];
        }

        return $missing;
    }
}
