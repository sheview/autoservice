<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;

/**
 * The company's asset categories by code prefix (upper case), for other modules that match
 * their own standard sets to categories (e.g. the standard PM checklists). Where two categories
 * share a prefix, the older one.
 */
class CategoryIdsByPrefix
{
    /**
     * @return array<string, int>
     */
    public function handle(): array
    {
        return AssetCategory::query()->orderByDesc('id')->get(['id', 'code_prefix'])
            ->mapWithKeys(fn (AssetCategory $category) => [strtoupper($category->code_prefix) => $category->id])
            ->all();
    }
}
