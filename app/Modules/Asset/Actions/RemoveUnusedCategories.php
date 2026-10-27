<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Inventory\Actions\CategoryIdsWithParts;
use App\Modules\Maintenance\Actions\CategoryIdsWithChecklists;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes (soft delete) in one go the picked categories that nothing uses: no asset, no part and
 * no PM checklist. A category in use stays; one that repeats another is merged instead
 * (MergeAssetCategories), so what it holds moves along.
 */
class RemoveUnusedCategories
{
    /**
     * @return Collection<int, AssetCategory>
     */
    public function unused(): Collection
    {
        $used = Asset::query()->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)
            ->merge(app(CategoryIdsWithParts::class)->handle())
            ->merge(app(CategoryIdsWithChecklists::class)->handle())
            ->unique()->all();

        return AssetCategory::query()->whereNotIn('id', $used ?: [0])->orderBy('name')->get();
    }

    /**
     * @param  list<int>  $ids  the ones picked; any of them in use is kept
     * @return int how many were removed
     */
    public function handle(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $unused = $this->unused()->whereIn('id', $ids);
            $unused->each(fn (AssetCategory $category) => app(DeleteAssetCategory::class)->handle($category));

            return $unused->count();
        });
    }
}
