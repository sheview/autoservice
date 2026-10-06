<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoomManager;

/**
 * Who may record entering and leaving a room for a request when signed in: the requester, and our
 * users who look after the room. (Guards use the request's guard link instead.) Entering is
 * allowed from EARLY_MINUTES before the planned start until the planned end.
 */
class RoomVisit
{
    public const EARLY_MINUTES = 60;

    public static function canRecord(User $user, RoomAccessRequest $request): bool
    {
        if ($user->customer_id !== null) {
            return false;
        }

        return (int) $request->requester_id === $user->id
            || ServerRoomManager::query()->where('server_room_id', $request->server_room_id)->where('user_id', $user->id)->exists();
    }

    /** Inside after the planned end: shown as overstaying (leaving is still recorded). */
    public static function overstaying(RoomAccessRequest $request): bool
    {
        return $request->status === RoomAccessRequest::STATUS_INSIDE && $request->planned_end->isPast();
    }
}
