<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisitEvent;

/**
 * Stops a customer's report link at once (it cannot be brought back; make a new one instead).
 */
class RevokePmReportShare
{
    public function __construct(private LogPmEvent $logEvent) {}

    public function handle(PmReportShare $share, User $actor): void
    {
        if ($share->revoked_at !== null) {
            return;
        }

        $share->update(['revoked_at' => now(), 'revoked_by_name' => $actor->name]);
        $this->logEvent->handle($share->visit, PmVisitEvent::TYPE_SHARE_REVOKED, $actor, $share->recipient_name, null, null, ['share_id' => $share->id]);

        activity()->performedOn($share->visit)->causedBy($actor)->event('pm_share_revoked')
            ->withProperties(['visit_no' => $share->visit->visit_no, 'share_id' => $share->id])
            ->log(__('maintenance.log.share_revoked', ['no' => $share->visit->visit_no]));
    }
}
