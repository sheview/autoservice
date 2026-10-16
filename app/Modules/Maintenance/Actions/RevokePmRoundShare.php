<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmRoundShare;

/**
 * Stops a customer's round report link at once (it cannot be brought back; make a new one instead).
 */
class RevokePmRoundShare
{
    public function handle(PmRoundShare $share, User $actor): void
    {
        if ($share->revoked_at !== null) {
            return;
        }

        $share->update(['revoked_at' => now(), 'revoked_by_name' => $actor->name]);

        activity()->performedOn($share)->causedBy($actor)->event('pm_round_share_revoked')
            ->withProperties(['share_id' => $share->id])
            ->log(__('maintenance.log.round_share_revoked', ['from' => $share->from_on->toDateString(), 'to' => $share->to_on->toDateString()]));
    }
}
