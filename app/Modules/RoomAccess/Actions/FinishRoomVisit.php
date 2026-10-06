<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RequestHistory;
use Illuminate\Validation\ValidationException;

/**
 * After leaving: the requester sums up the work done (and may link the ticket now), and confirms
 * that all the equipment listed came back out (when there was any).
 */
class FinishRoomVisit
{
    public function handle(RoomAccessRequest $request, User $actor, string $summary, bool $itemsConfirmed, ?int $ticketId = null): RoomAccessRequest
    {
        if ($request->status !== RoomAccessRequest::STATUS_EXITED || (int) $request->requester_id !== $actor->id) {
            throw ValidationException::withMessages(['work_summary' => __('room_access.visit.not_finishable')]);
        }
        if (trim($summary) === '') {
            throw ValidationException::withMessages(['work_summary' => __('room_access.visit.summary_required')]);
        }
        if ($request->items()->exists() && ! $itemsConfirmed) {
            throw ValidationException::withMessages(['items_confirmed' => __('room_access.visit.items_confirm_required')]);
        }

        $request->fill([
            'work_summary' => trim($summary),
            'ticket_id' => $ticketId ?? $request->ticket_id,
            'items_confirmed_at' => $itemsConfirmed ? now() : null,
            'items_confirmed_by_name' => $itemsConfirmed ? $actor->name : null,
        ])->save();
        RequestHistory::record($request, 'finished', RoomAccessRequest::STATUS_EXITED, $actor);

        return $request;
    }
}
