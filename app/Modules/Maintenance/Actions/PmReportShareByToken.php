<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\ShareToken;

/**
 * Opens a customer's report link in the current company (the caller resolved it): the share,
 * its work order and the version it shows, or null when anything is wrong (malformed token,
 * unknown, revoked, expired, the work order cancelled or deleted, nothing published). Every
 * opening is counted and kept in the work order's history.
 */
class PmReportShareByToken
{
    public function __construct(private LogPmEvent $logEvent) {}

    /**
     * @return array{share: PmReportShare, visit: PmVisit, version: PmReportVersion}|null
     */
    public function handle(string $token, ?string $ip = null, string $what = PmVisitEvent::TYPE_SHARE_VIEWED): ?array
    {
        if (! ShareToken::looksValid($token)) {
            return null;
        }

        $share = PmReportShare::query()->where('token_hash', ShareToken::hash($token))->first();
        $visit = $share?->visit;
        if ($share === null || ! $share->isActive() || $visit === null || $visit->status === PmVisit::STATUS_CANCELLED) {
            return null;
        }

        $version = $share->pm_report_version_id
            ? $share->version
            : $visit->versions()->where('version', $visit->published_version)->first();
        if ($version === null) {
            return null;
        }

        $share->forceFill(['view_count' => $share->view_count + 1, 'last_viewed_at' => now()])->save();
        $this->logEvent->handle($visit, $what, null, $share->recipient_name, null, null, [
            'share_id' => $share->id, 'version' => $version->version, 'ip' => $ip,
        ]);

        return ['share' => $share, 'visit' => $visit, 'version' => $version];
    }
}
