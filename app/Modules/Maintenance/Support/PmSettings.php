<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * A company's PM settings: tenants.settings "pm" (SavePmSettings), with defaults.
 *
 *   review_step            submitted work is checked (review) before it is approved; off = it goes
 *                          straight to the approver
 *   approval_step          an approver approves the report; off = handed-in work is approved at once
 *                          (its report version made by the system)
 *   require_technician_signature, require_customer_signature
 *                          the work can only be handed in signed
 *   require_approver_signature
 *                          the approver signs when approving
 *   share_days             how long a new customer report link lasts by default
 *   sign_link_days         how long a link for the customer to sign on their phone lasts
 *   report_note            printed at the end of every PM report (terms, contact)
 */
final class PmSettings
{
    public const KEY = 'pm';

    public const NOTE_MAX = 1000;

    public const MAX_SIGN_LINK_DAYS = 30;

    public function __construct(
        public readonly bool $reviewStep = true,
        public readonly bool $requireTechnicianSignature = true,
        public readonly bool $requireCustomerSignature = false,
        public readonly int $shareDays = PmReportShare::DEFAULT_DAYS,
        public readonly ?string $reportNote = null,
        public readonly bool $approvalStep = true,
        public readonly bool $requireApproverSignature = false,
        public readonly int $signLinkDays = 3,
    ) {}

    public static function of(?Tenant $tenant = null): self
    {
        $tenant ??= app(TenantContext::class)->tenant();
        $saved = $tenant?->settings[self::KEY] ?? [];

        return new self(
            reviewStep: (bool) ($saved['review_step'] ?? true),
            requireTechnicianSignature: (bool) ($saved['require_technician_signature'] ?? true),
            requireCustomerSignature: (bool) ($saved['require_customer_signature'] ?? false),
            shareDays: max(1, min(PmReportShare::MAX_DAYS, (int) ($saved['share_days'] ?? PmReportShare::DEFAULT_DAYS))),
            reportNote: filled($saved['report_note'] ?? null) ? (string) $saved['report_note'] : null,
            approvalStep: (bool) ($saved['approval_step'] ?? true),
            requireApproverSignature: (bool) ($saved['require_approver_signature'] ?? false),
            signLinkDays: max(1, min(self::MAX_SIGN_LINK_DAYS, (int) ($saved['sign_link_days'] ?? 3))),
        );
    }

    /** @return array<string, mixed> as stored and as the settings page edits it */
    public function toArray(): array
    {
        return [
            'review_step' => $this->reviewStep,
            'approval_step' => $this->approvalStep,
            'require_technician_signature' => $this->requireTechnicianSignature,
            'require_customer_signature' => $this->requireCustomerSignature,
            'require_approver_signature' => $this->requireApproverSignature,
            'share_days' => $this->shareDays,
            'sign_link_days' => $this->signLinkDays,
            'report_note' => $this->reportNote,
        ];
    }
}
