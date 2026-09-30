<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;

/**
 * Asset category names of the current tenant, for other modules (e.g. PM checklists per category).
 */
class CategoryNames
{
    /**
     * @param  bool  $withTrashed  include deleted categories (to show names on old records)
     * @return array<int, string> id => name, by name
     */
    public function handle(bool $withTrashed = false): array
    {
        return AssetCategory::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
