<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessToken;

/**
 * The permit link (and through it its request), in the current company only (a link of another company is
 * simply not found here), or null. An expired or revoked link still finds it: the counter page
 * then says it is no longer valid instead of showing nothing.
 */
class RequestByPermitToken
{
    public function handle(string $token): ?RoomAccessToken
    {
        // Same work for a malformed token as for an unknown one.
        $row = RoomAccessToken::query()->where('purpose', RoomAccessToken::PERMIT)
            ->where('token', RoomAccessToken::looksValid($token) ? $token : '-')->first();
        if ($row === null) {
            return null;
        }
        $row->forceFill(['last_used_at' => now()])->save();

        return $row;
    }
}
