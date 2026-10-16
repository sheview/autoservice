<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmRoundShare;
use App\Modules\Maintenance\Support\ShareToken;

/**
 * Opens a customer's round report link in the current company (the caller resolved it): the
 * share, or null when anything is wrong (malformed token, unknown, revoked, expired). Every
 * opening is counted.
 */
class PmRoundShareByToken
{
    public function handle(string $token): ?PmRoundShare
    {
        if (! ShareToken::looksValid($token)) {
            return null;
        }

        $share = PmRoundShare::query()->where('token_hash', ShareToken::hash($token))->first();
        if ($share === null || ! $share->isActive()) {
            return null;
        }

        $share->forceFill(['view_count' => $share->view_count + 1, 'last_viewed_at' => now()])->save();

        return $share;
    }
}
