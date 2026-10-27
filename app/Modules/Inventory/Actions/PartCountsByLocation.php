<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * How many parts are kept at each storage location (Asset module's list of them).
 */
class PartCountsByLocation
{
    /**
     * @param  list<int>  $locationIds
     * @return array<int, int> location id => parts
     */
    public function handle(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return Part::query()->whereIn('storage_location_id', $locationIds)
            ->selectRaw('storage_location_id, count(*) as parts')->groupBy('storage_location_id')
            ->pluck('parts', 'storage_location_id')->map(fn ($count) => (int) $count)->all();
    }
}
