<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which report of a work order a user may have:
 *
 *   a customer account   only the version given to the customer (published); none = not found
 *   staff                the version asked for, else the newest approved one, else (null) the
 *                        work order as it is now, printed as a draft
 */
class PmReportVersionFor
{
    public function handle(PmVisit $visit, User $user, ?int $requested = null): ?PmReportVersion
    {
        if ($user->customer_id !== null) {
            return $this->version($visit, $visit->published_version) ?? throw new NotFoundHttpException;
        }

        if ($requested !== null) {
            return $this->version($visit, $requested) ?? throw new NotFoundHttpException;
        }

        return $this->version($visit, $visit->current_version);
    }

    private function version(PmVisit $visit, int $number): ?PmReportVersion
    {
        return $number > 0 ? $visit->versions()->where('version', $number)->first() : null;
    }
}
