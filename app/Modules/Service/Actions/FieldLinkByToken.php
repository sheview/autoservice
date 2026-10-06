<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\TicketFieldLink;

/**
 * The ticket link of a token, in the current company only (another company's is simply not found
 * here), or null; noting when it was last opened.
 */
class FieldLinkByToken
{
    public function handle(string $token): ?TicketFieldLink
    {
        // Same work for a malformed token as for an unknown one.
        $link = TicketFieldLink::query()->with('ticket')->where('token', TicketFieldLink::looksValid($token) ? $token : '-')->first();
        $link?->forceFill(['last_used_at' => now()])->save();

        return $link;
    }
}
