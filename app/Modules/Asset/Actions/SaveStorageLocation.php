<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\StorageLocation;

/**
 * Creates or updates a storage location.
 */
class SaveStorageLocation
{
    /**
     * @param  array{name: string, notes?: string|null, is_active?: bool}  $data
     */
    public function handle(?StorageLocation $location, array $data): StorageLocation
    {
        $location ??= new StorageLocation;
        $location->fill([
            'name' => trim($data['name']),
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? $location->is_active ?? true),
        ])->save();

        // Assets whose location already names it (typed or imported before it existed) are kept there.
        if ($location->is_active) {
            Asset::query()->whereNull('storage_location_id')->where('location', $location->name)
                ->update(['storage_location_id' => $location->id]);
        }

        return $location;
    }
}
