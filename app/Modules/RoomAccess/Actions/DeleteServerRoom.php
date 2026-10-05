<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\ServerRoom;

/**
 * Removes a room (soft delete): its rules and the requests made for it stay as they were.
 */
class DeleteServerRoom
{
    public function handle(ServerRoom $room): void
    {
        $room->delete();
    }
}
