<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RequestHistory;
use App\Modules\RoomAccess\Support\RoomVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that the team came out (by a signed-in user, or a guard through the guard link), noting
 * when it is later than the planned end (of that day's hours, on a standing request, which then
 * waits for the next day until its period ends). The requester then sums up the work (FinishRoomVisit).
 */
class RecordRoomExit
{
    public function handle(RoomAccessRequest $request, ?User $actor, ?string $guardName = null): RoomAccessRequest
    {
        return DB::transaction(function () use ($request, $actor, $guardName) {
            $request = RoomAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== RoomAccessRequest::STATUS_INSIDE) {
                throw ValidationException::withMessages(['visit' => __('room_access.visit.not_inside')]);
            }

            $by = $actor?->name ?? __('room_access.visit.by_guard', ['name' => $guardName]);
            $end = RoomVisit::visitEnd($request);
            $late = $end->isPast() ? __('room_access.visit.left_late', ['minutes' => (int) $end->diffInMinutes(now())]) : null;
            // A standing request is ready for the next day until its period ends.
            $status = $request->isRecurring() && $request->planned_end->isFuture() ? RoomAccessRequest::STATUS_APPROVED : RoomAccessRequest::STATUS_EXITED;
            $request->fill(['status' => $status, 'exited_at' => now(), 'exited_by_name' => $by])->save();
            $request->visits()->whereNull('exited_at')->update(['exited_at' => now(), 'exited_by_name' => $by]);
            RequestHistory::record($request, 'exited', RoomAccessRequest::STATUS_INSIDE, $actor, collect([$actor ? null : $by, $late])->filter()->implode(' · ') ?: null, $by);

            return $request;
        });
    }
}
