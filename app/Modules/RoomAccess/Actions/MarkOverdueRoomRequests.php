<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RequestHistory;
use Illuminate\Support\Facades\DB;

/**
 * Approved requests nobody went in on before the planned end become "overdue" (no longer usable;
 * their links stop), in the current company. A standing request used on some day of its period
 * ends as "exited" instead (the requester then sums up the work).
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
                    // A standing request used at least once has simply come to its end.
                    $used = $request->isRecurring() && $request->visits()->exists();
                    $status = $used ? RoomAccessRequest::STATUS_EXITED : RoomAccessRequest::STATUS_OVERDUE;
                    $request->update(['status' => $status]);
                    RequestHistory::record($request, $used ? 'period_ended' : 'overdue', RoomAccessRequest::STATUS_APPROVED, null, null, __('ui.common.system'));
                    $this->revokeTokens->handle($request);
                });
                $count++;
            });

        return $count;
    }
}
