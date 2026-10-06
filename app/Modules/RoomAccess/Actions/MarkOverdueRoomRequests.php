<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RequestHistory;
use Illuminate\Support\Facades\DB;

/**
 * Approved requests nobody went in on before the planned end become "overdue" (no longer usable;
 * their links stop), in the current company.
 */
class MarkOverdueRoomRequests
{
    public function __construct(private RevokeRoomAccessTokens $revokeTokens) {}

    public function handle(): int
    {
        $count = 0;
        RoomAccessRequest::query()->where('status', RoomAccessRequest::STATUS_APPROVED)->where('planned_end', '<', now())
            ->get()->each(function (RoomAccessRequest $request) use (&$count) {
                DB::transaction(function () use ($request) {
                    $request->update(['status' => RoomAccessRequest::STATUS_OVERDUE]);
                    RequestHistory::record($request, 'overdue', RoomAccessRequest::STATUS_APPROVED, null, null, __('ui.common.system'));
                    $this->revokeTokens->handle($request);
                });
                $count++;
            });

        return $count;
    }
}
