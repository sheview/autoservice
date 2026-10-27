<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;

/**
 * Asset categories of the current tenant for a select in other modules (parts share them), with
 * whether their items are counted by serial number.
 */
class CategoryOptions
{
    /**
     * @return list<array{id: int, name: string, requires_serial: bool}>
     */
    public function handle(): array
    {
        return AssetCategory::query()->orderBy('name')->get(['id', 'name', 'requires_serial'])
            ->map(fn (AssetCategory $category) => $category->only(['id', 'name', 'requires_serial']))->all();
    }
}
