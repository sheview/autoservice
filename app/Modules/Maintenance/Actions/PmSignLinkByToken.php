<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmSignLink;
use App\Modules\Maintenance\Support\ShareToken;

/**
 * The customer's sign link in the current company (the caller resolved it), or null when anything is
 * wrong: malformed or unknown token, used, revoked, expired, or the work order already locked or no
 * longer waiting for a signature.
 */
class PmSignLinkByToken
{
    public function handle(string $token): ?PmSignLink
    {
        if (! ShareToken::looksValid($token)) {
            return null;
        }

        $link = PmSignLink::query()->where('token_hash', ShareToken::hash($token))->first();
        $visit = $link?->visit;

        return $link !== null && $link->isActive() && $visit !== null && ! $visit->isLocked()
            && in_array($visit->status, CreatePmSignLink::STATUSES, true) ? $link : null;
    }
}
