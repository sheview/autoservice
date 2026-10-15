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
 *   require_technician_signature, require_customer_signature
 *                          the work can only be handed in signed
 *   share_days             how long a new customer link lasts by default
 *   report_note            printed at the end of every PM report (terms, contact)
 */
final class PmSettings
{
    public const KEY = 'pm';

    public const NOTE_MAX = 1000;

    public function __construct(
        public readonly bool $reviewStep = true,
        public readonly bool $requireTechnicianSignature = true,
        public readonly bool $requireCustomerSignature = false,
        public readonly int $shareDays = PmReportShare::DEFAULT_DAYS,
        public readonly ?string $reportNote = null,
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
        );
    }

    /** @return array<string, mixed> as stored and as the settings page edits it */
    public function toArray(): array
    {
        return [
            'review_step' => $this->reviewStep,
            'require_technician_signature' => $this->requireTechnicianSignature,
            'require_customer_signature' => $this->requireCustomerSignature,
            'share_days' => $this->shareDays,
            'report_note' => $this->reportNote,
        ];
    }
}
