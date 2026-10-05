<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;

/**
 * Stops the links of a request at once (all, or those of one purpose): a cancelled request's
 * permit no longer shows as valid at the counter.
 */
class RevokeRoomAccessTokens
{
    public function handle(RoomAccessRequest $request, ?User $actor = null, ?string $purpose = null): int
    {
        return RoomAccessToken::query()->where('request_id', $request->id)->whereNull('revoked_at')
            ->when($purpose, fn ($q) => $q->where('purpose', $purpose))
            ->update(['revoked_at' => now(), 'revoked_by_name' => $actor?->name]);
    }
}
