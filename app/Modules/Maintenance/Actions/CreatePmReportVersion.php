<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;

/**
 * Freezes the approved report as the work order's next version (1, 2, …): the snapshot both PDFs
 * are made from from then on. Runs inside the approval's transaction (MovePmVisit).
 */
class CreatePmReportVersion
{
    public function __construct(private PmReportData $reportData, private LogPmEvent $logEvent) {}

    public function handle(PmVisit $visit, User $actor): PmReportVersion
    {
        $number = (int) $visit->versions()->max('version') + 1;
        $version = $visit->versions()->create([
            'version' => $number,
            'snapshot' => $this->reportData->handle($visit),
            'approved_by' => $actor->id,
            'approved_by_name' => $actor->name,
            'created_at' => now(),
        ]);

        $visit->update(['current_version' => $number]);
        $this->logEvent->handle($visit, PmVisitEvent::TYPE_VERSION, $actor, null, null, null, ['version' => $number]);

        return $version;
    }
}
