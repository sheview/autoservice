<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessApproval;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomApprovalStep;

/**
 * Where a pending request stands in its room's approval: the steps in order (one step of our
 * company when the room names none), which of them this round has approved, and the step that
 * decides now. Who may decide a step: the user it names, or (none named) anyone holding
 * room-access.approve in scope; never the requester. A step of the customer's side waits for
 * their link (not offered yet), so nobody here can skip it.
 */
class ApprovalFlow
{
    /**
     * @return list<array{position: int, side: string, approver_user_id: int|null}>
     */
    public static function steps(RoomAccessRequest $request): array
    {
        $steps = RoomApprovalStep::query()->where('server_room_id', $request->server_room_id)->orderBy('position')->get()
            ->map(fn (RoomApprovalStep $step) => ['position' => (int) $step->position, 'side' => $step->side, 'approver_user_id' => $step->approver_user_id])
            ->all();

        return $steps !== [] ? $steps : [['position' => 1, 'side' => RoomApprovalStep::SIDE_COMPANY, 'approver_user_id' => null]];
    }

    /**
     * The step deciding now (the first one this round has not approved), or null when all have.
     *
     * @return array{position: int, side: string, approver_user_id: int|null}|null
     */
    public static function current(RoomAccessRequest $request): ?array
    {
        $approved = RoomAccessApproval::query()->where('request_id', $request->id)->where('round', $request->round)
            ->where('decision', RoomAccessApproval::APPROVED)->pluck('step')->all();

        foreach (self::steps($request) as $step) {
            if (! in_array($step['position'], $approved, true)) {
                return $step;
            }
        }

        return null;
    }

    /** Whether the user may decide the request now. */
    public static function canDecide(User $user, RoomAccessRequest $request): bool
    {
        if ($request->status !== RoomAccessRequest::STATUS_PENDING || (int) $request->requester_id === $user->id || $user->customer_id !== null) {
            return false;
        }
        $step = self::current($request);
        if ($step === null || $step['side'] !== RoomApprovalStep::SIDE_COMPANY || ! $user->can('room-access.approve')) {
            return false;
        }

        return $step['approver_user_id'] === null || (int) $step['approver_user_id'] === $user->id;
    }
}
