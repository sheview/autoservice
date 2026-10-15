<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A link for a customer to see and download a published PM report without an account. It lasts
 * the given number of days (the company's default otherwise, PmSettings) and shows the version
 * published now, or (follow_latest) whatever version is published later. Returns the share and
 * its URL, the only time the link exists in plain text.
 */
class CreatePmReportShare
{
    public function __construct(private TenantContext $context, private LogPmEvent $logEvent) {}

    /**
     * @param  array{recipient_name?: string|null, recipient_email?: string|null, days?: int|null, allow_download?: bool, follow_latest?: bool}  $data  validated
     * @return array{share: PmReportShare, url: string}
     */
    public function handle(PmVisit $visit, array $data, User $actor): array
    {
        if ($visit->published_version === 0 || $visit->status === PmVisit::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['share' => __('maintenance.shares.not_published')]);
        }

        $token = ShareToken::make();
        $days = (int) ($data['days'] ?? PmSettings::of()->shareDays);

        $share = DB::transaction(function () use ($visit, $data, $actor, $token, $days) {
            $share = $visit->shares()->create([
                'pm_report_version_id' => ! empty($data['follow_latest'])
                    ? null
                    : $visit->versions()->where('version', $visit->published_version)->value('id'),
                'token_hash' => ShareToken::hash($token),
                'recipient_name' => filled($data['recipient_name'] ?? null) ? trim($data['recipient_name']) : null,
                'recipient_email' => filled($data['recipient_email'] ?? null) ? trim($data['recipient_email']) : null,
                'allow_download' => (bool) ($data['allow_download'] ?? true),
                'expires_at' => now()->addDays($days)->endOfDay(),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ]);
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_SHARE_CREATED, $actor, $share->recipient_name, null, null, [
                'share_id' => $share->id, 'expires_at' => $share->expires_at->toIso8601String(), 'version' => $share->pm_report_version_id ? $visit->published_version : 'latest',
            ]);

            return $share;
        });

        activity()->performedOn($visit)->causedBy($actor)->event('pm_share_created')
            ->withProperties(['visit_no' => $visit->visit_no, 'share_id' => $share->id, 'recipient' => $share->recipient_name, 'expires_at' => $share->expires_at->toIso8601String()])
            ->log(__('maintenance.log.share_created', ['no' => $visit->visit_no]));

        return ['share' => $share, 'url' => PublicUrl::forTenant($this->context->tenant(), '/pm-report/'.$token)];
    }
}
