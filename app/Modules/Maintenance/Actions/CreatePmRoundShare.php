<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmRoundShare;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * A link for a customer to see and download a round report (PmRoundReport) without an account:
 * the approved work orders of the customer (or one plan) due within the period, read whenever
 * the link is opened. It lasts the given number of days (the company's default otherwise).
 * Returns the share and its URL, the only time the link exists in plain text.
 */
class CreatePmRoundShare
{
    public function __construct(private TenantContext $context, private PmRoundReport $report) {}

    /**
     * @param  array{customer_id: int, plan_id?: int|null, from: string, to: string, recipient_name?: string|null, recipient_email?: string|null, days?: int|null, allow_download?: bool}  $data  validated
     * @return array{share: PmRoundShare, url: string}
     */
    public function handle(array $data, User $actor): array
    {
        $filters = ['customer_id' => (int) $data['customer_id'], 'plan_id' => $data['plan_id'] ?? null, 'from' => $data['from'], 'to' => $data['to']];
        if ($this->report->visits($filters)->isEmpty()) {
            throw ValidationException::withMessages(['share' => __('maintenance.round_report.empty')]);
        }

        $token = ShareToken::make();
        $days = (int) ($data['days'] ?? PmSettings::of()->shareDays);

        $share = PmRoundShare::create([
            'customer_id' => $filters['customer_id'],
            'pm_plan_id' => $filters['plan_id'],
            'from_on' => $filters['from'],
            'to_on' => $filters['to'],
            'token_hash' => ShareToken::hash($token),
            'recipient_name' => filled($data['recipient_name'] ?? null) ? trim($data['recipient_name']) : null,
            'recipient_email' => filled($data['recipient_email'] ?? null) ? trim($data['recipient_email']) : null,
            'allow_download' => (bool) ($data['allow_download'] ?? true),
            'expires_at' => now()->addDays($days)->endOfDay(),
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ]);

        activity()->performedOn($share)->causedBy($actor)->event('pm_round_share_created')
            ->withProperties(['share_id' => $share->id, 'from' => $filters['from'], 'to' => $filters['to'], 'recipient' => $share->recipient_name, 'expires_at' => $share->expires_at->toIso8601String()])
            ->log(__('maintenance.log.round_share_created', ['from' => $filters['from'], 'to' => $filters['to']]));

        return ['share' => $share, 'url' => PublicUrl::forTenant($this->context->tenant(), '/pm-round/'.$token)];
    }
}
