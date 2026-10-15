<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Saves the company's PM settings (tenants.settings "pm", PmSettings), with who changed them in
 * the activity log.
 */
class SavePmSettings
{
    /**
     * @param  array{review_step: bool, approval_step?: bool, require_technician_signature: bool, require_customer_signature: bool,
     *     require_approver_signature?: bool, share_days: int, sign_link_days?: int, report_note: string|null}  $data  validated
     */
    public function handle(Tenant $tenant, array $data, User $actor): PmSettings
    {
        $old = PmSettings::of($tenant)->toArray();
        $settings = $tenant->settings ?? [];
        $settings[PmSettings::KEY] = [
            'review_step' => (bool) $data['review_step'],
            'approval_step' => (bool) ($data['approval_step'] ?? $old['approval_step']),
            'require_technician_signature' => (bool) $data['require_technician_signature'],
            'require_customer_signature' => (bool) $data['require_customer_signature'],
            'require_approver_signature' => (bool) ($data['require_approver_signature'] ?? $old['require_approver_signature']),
            'share_days' => (int) $data['share_days'],
            'sign_link_days' => (int) ($data['sign_link_days'] ?? $old['sign_link_days']),
            'report_note' => filled($data['report_note'] ?? null) ? trim($data['report_note']) : null,
        ];
        $tenant->settings = $settings;
        $tenant->save();

        activity()->performedOn($tenant)->causedBy($actor)->event('pm_settings_updated')
            ->withProperties(['old' => $old, 'attributes' => $settings[PmSettings::KEY]])
            ->log(__('maintenance.log.settings'));

        return PmSettings::of($tenant);
    }
}
