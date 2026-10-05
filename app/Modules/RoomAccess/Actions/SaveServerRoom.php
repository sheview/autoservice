<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomApprovalStep;
use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a server room: its settings, the users of ours who look after it, and its
 * approval (one step of our company for now: a named approver, or anyone holding
 * room-access.approve). Its rules are versions of their own (PublishRoomRules).
 */
class SaveServerRoom
{
    /**
     * @param  array<string, mixed>  $data  validated room fields
     * @param  list<int>  $managerIds
     */
    public function handle(?ServerRoom $room, array $data, array $managerIds, ?int $approverId, User $actor): ServerRoom
    {
        return DB::transaction(function () use ($room, $data, $managerIds, $approverId) {
            $room ??= new ServerRoom;
            $room->fill($data)->save();

            $room->managers()->whereNotIn('user_id', $managerIds)->delete();
            foreach (array_diff($managerIds, $room->managers()->pluck('user_id')->all()) as $userId) {
                $room->managers()->create(['user_id' => $userId]);
            }

            RoomApprovalStep::query()->updateOrCreate(
                ['server_room_id' => $room->id, 'position' => 1],
                ['side' => RoomApprovalStep::SIDE_COMPANY, 'approver_user_id' => $approverId],
            );

            return $room;
        });
    }
}
