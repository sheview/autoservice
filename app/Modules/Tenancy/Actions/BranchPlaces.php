<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Branch;

/**
 * The company's branches placed on the map (lat / lng set), for other modules (the map page).
 */
class BranchPlaces
{
    /**
     * @return list<array{id: int, code: string, name: string, address: string|null, province: string|null, lat: float, lng: float}>
     */
    public function handle(): array
    {
        return Branch::query()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'address', 'province', 'lat', 'lng'])
            ->map(fn (Branch $branch) => [
                ...$branch->only(['id', 'code', 'name', 'address', 'province']),
                'lat' => (float) $branch->lat,
                'lng' => (float) $branch->lng,
            ])
            ->all();
    }
}
