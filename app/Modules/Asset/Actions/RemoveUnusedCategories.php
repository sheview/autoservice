<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Inventory\Actions\CategoryIdsWithParts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes (soft delete) in one go the picked categories that nothing uses: no asset and no part.
 * A category in use stays; one that repeats another is merged instead
 * (MergeAssetCategories), so what it holds moves along.
 */
class RemoveUnusedCategories
{
    /**
     * @return Collection<int, AssetCategory>
     */
    public function unused(): Collection
    {
        // PM templates do not count: the standard ones are attached to every standard category a
        // company starts with, and with no device in the category they check nothing.
        $used = Asset::query()->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)
            ->merge(app(CategoryIdsWithParts::class)->handle())
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
