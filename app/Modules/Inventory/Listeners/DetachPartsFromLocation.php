<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Asset\Events\StorageLocationDeleted;
use App\Modules\Inventory\Models\Part;

/**
 * A storage location was removed (Asset module): the parts kept there stay, with no location.
 */
class DetachPartsFromLocation
{
    public function handle(StorageLocationDeleted $event): void
    {
        Part::withTrashed()->where('storage_location_id', $event->locationId)->update(['storage_location_id' => null]);
    }
}
