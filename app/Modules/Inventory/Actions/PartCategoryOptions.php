<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCategory;

/**
 * The part categories of the company for a select, with whether their new parts are tracked.
 */
class PartCategoryOptions
{
    /**
     * @return list<array{id: int, name: string, track_serial: bool}>
     */
    public function handle(): array
    {
        return PartCategory::query()->orderBy('name')->get(['id', 'name', 'track_serial'])
            ->map(fn (PartCategory $category) => $category->only(['id', 'name', 'track_serial']))->all();
    }
}
