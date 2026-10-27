<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Events\StorageLocationDeleted;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\StorageLocation;
use Illuminate\Support\Facades\DB;

/**
 * Removes a storage location; the assets and parts kept there stay, with no location.
 */
class DeleteStorageLocation
{
    public function handle(StorageLocation $location): void
    {
        DB::transaction(function () use ($location) {
            Asset::withTrashed()->where('storage_location_id', $location->id)->update(['storage_location_id' => null]);
            $location->delete();
            StorageLocationDeleted::dispatch($location->id);
        });
    }
}
