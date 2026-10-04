<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Assets of the current company for people of another company (cross-company sharing): a few
 * plain fields, read-only. Called inside the owner company through ShareGateway, which has
 * already decided the person may look; branch scopes of the owner do not apply to them, only the
 * branches the share names (none named = every branch).
 */
class SharedAssetSearch
{
    /**
     * @return list<array{asset_code: string, name: string, category: string|null, brand: string|null, model: string|null,
     *     serial_number: string|null, status: string, location: string|null, quantity: int, unit: string|null}>
     */
    /**
     * @param  list<int>  $branchIds  only assets of these branches; empty = all
     */
    public function handle(string $search, array $branchIds = [], int $limit = 30): array
    {
        $search = trim($search);

        return Asset::query()
            ->with('category:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('asset_code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")
                ->orWhere('model', 'ilike', "%{$search}%")
                ->orWhere('serial_number', 'ilike', "%{$search}%")))
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->when($branchIds !== [], fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('asset_code')
            ->limit($limit)
            ->get()
            ->map(fn (Asset $asset) => [
                ...$asset->only(['asset_code', 'name', 'brand', 'model', 'serial_number', 'status', 'location', 'quantity', 'unit']),
                'category' => $asset->category?->name,
            ])
            ->all();
    }
}
