<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Jobs\SendGuardLinkMail;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;

/**
 * Sends a request's guard link to the room's guards who have an e-mail (on the queue, once the
 * approval is saved).
 */
class SendGuardLink
{
    public function handle(RoomAccessRequest $request, RoomAccessToken $token): void
    {
        if (collect($request->room?->guard_contacts ?? [])->contains(fn (array $guard) => filled($guard['email'] ?? null))) {
            SendGuardLinkMail::dispatch($token->id)->afterCommit();
        }
    }
}
