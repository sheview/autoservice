<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessEvent;
use App\Modules\RoomAccess\Models\RoomAccessRequest;

/**
 * Writes one step of a request's history (status from → to, who, note) and the company's
 * activity log entry for it.
 */
class RequestHistory
{
    public static function record(RoomAccessRequest $request, string $action, ?string $from, ?User $actor, ?string $note = null, ?string $actorName = null): void
    {
        RoomAccessEvent::create([
            'request_id' => $request->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $request->status,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? $actorName,
            'note' => $note,
        ]);

        activity()->performedOn($request)->causedBy($actor)->event("room_access_{$action}")
            ->withProperties(['request_no' => $request->request_no, 'from' => $from, 'to' => $request->status, 'note' => $note])
            ->log(__("room_access.log.{$action}", ['no' => $request->request_no]));
    }
}
