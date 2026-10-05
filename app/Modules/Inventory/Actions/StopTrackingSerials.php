<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;

/**
 * Stops following a part by serial number: it is counted by quantity again. Its pieces and
 * their history are kept as they are (and offered again if tracking starts once more).
 */
class StopTrackingSerials
{
    public function handle(Part $part, User $actor): Part
    {
        $part->forceFill(['track_serial' => false])->save();

        activity()->performedOn($part)->causedBy($actor)->event('part_tracking_stopped')
            ->withProperties(['code' => $part->code])
            ->log(__('inventory.units.log.stopped', ['code' => $part->code]));

        return $part;
    }
}
