<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RequestHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a request before anyone has gone in (a draft, waiting for approval, or approved),
 * with the reason given.
 */
class CancelRoomAccessRequest
{
    public const CANCELLABLE = [RoomAccessRequest::STATUS_DRAFT, RoomAccessRequest::STATUS_PENDING, RoomAccessRequest::STATUS_APPROVED];

    public function handle(RoomAccessRequest $request, User $actor, ?string $reason = null): RoomAccessRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = RoomAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, self::CANCELLABLE, true)) {
                throw ValidationException::withMessages(['request' => __('room_access.requests.not_cancellable')]);
            }
            $from = $request->status;
            $request->update(['status' => RoomAccessRequest::STATUS_CANCELLED]);
            RequestHistory::record($request, 'cancelled', $from, $actor, $reason);

            return $request;
        });
    }
}
