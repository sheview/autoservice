<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Platform\Actions\SendAlert;
use App\Modules\RoomAccess\Models\RoomAccessRequest;

/**
 * Alerts about server room requests (lang/th/alerts.php "events.room_access_*"): which room of
 * which customer, when, who asks and how many go in, and a link to the request. Sent where the
 * company set its alerts (AlertSettings). Never the entrants' ID numbers.
 */
class RoomAccessAlert
{
    public static function send(string $event, RoomAccessRequest $request, ?string $actor = null, ?string $note = null): void
    {
        $request->loadMissing('room');

        app(SendAlert::class)->handle($event, [
            'no' => $request->request_no,
            'customer' => app(CustomerLabelNames::class)->handle()[$request->customer_id] ?? '-',
            'room' => $request->room?->name,
            'when' => $request->planned_start->format('d/m/Y H:i').' - '.$request->planned_end->format('d/m/Y H:i'),
            'requester' => $request->requester_name,
            'people' => $request->people()->count(),
            'purpose' => $request->purpose,
            'actor' => $actor,
            'note' => $note,
        ], route('room-access.requests.show', $request));
    }
}
