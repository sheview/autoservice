<?php

namespace App\Modules\Asset\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A storage location was removed: parts kept there (Inventory module) are left with none.
 */
class StorageLocationDeleted
{
    use Dispatchable;

    public function __construct(public int $locationId) {}
}
