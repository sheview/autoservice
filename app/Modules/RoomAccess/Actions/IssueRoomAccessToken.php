<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use Illuminate\Support\Facades\DB;

/**
 * Makes the link of a request for one purpose, revoking the one before it (a new link means the
 * old one no longer works). It works until RoomAccessToken::GRACE_HOURS after the planned end.
 */
class IssueRoomAccessToken
{
    public function handle(RoomAccessRequest $request, string $purpose, ?User $actor = null): RoomAccessToken
    {
        return DB::transaction(function () use ($request, $purpose, $actor) {
            RoomAccessToken::query()->where('request_id', $request->id)->where('purpose', $purpose)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by_name' => $actor?->name]);

            return RoomAccessToken::create([
                'request_id' => $request->id,
                'purpose' => $purpose,
                'token' => RoomAccessToken::newToken(),
                'expires_at' => $request->planned_end->copy()->addHours(RoomAccessToken::GRACE_HOURS),
                'created_by_name' => $actor?->name,
            ]);
        });
    }
}
