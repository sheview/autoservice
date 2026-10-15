<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\StandardCategories;
use Illuminate\Support\Facades\DB;

/**
 * Adds the standard categories (StandardCategories) the current company does not have yet: one
 * with the same name or code prefix counts as had, so a category the company changed or made
 * itself is never doubled or overwritten.
 */
class AddStandardCategories
{
    public function __construct(private SaveAssetCategory $saveCategory) {}

    /**
     * @return int how many were added
     */
    public function handle(): int
    {
        return DB::transaction(function () {
            $missing = $this->missing();
            foreach ($missing as $category) {
                $this->saveCategory->handle(null, $category);
            }

            return count($missing);
        });
    }

    /**
     * The standard categories the current company does not have yet.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        $existing = AssetCategory::query()->get(['name', 'code_prefix']);
        $names = $existing->map(fn (AssetCategory $c) => mb_strtolower(trim($c->name)))->all();
        $prefixes = $existing->map(fn (AssetCategory $c) => strtoupper($c->code_prefix))->all();

        return array_values(array_filter(StandardCategories::all(), fn (array $category) => ! in_array(mb_strtolower($category['name']), $names, true)
            && ! in_array($category['code_prefix'], $prefixes, true)));
    }
}
