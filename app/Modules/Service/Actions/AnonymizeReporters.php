<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyProfile;

/**
 * Blanks out who reported a problem with a QR code — name, phone, e-mail — once the job ended
 * (closed or cancelled) longer ago than the company keeps them (CompanyProfile). The job itself
 * stays. Runs in the current company.
 */
class AnonymizeReporters
{
    /** @return int how many tickets were blanked out */
    public function handle(Tenant $tenant): int
    {
        $before = now()->subDays(CompanyProfile::reporterRetentionDays($tenant));

        return Ticket::query()
            ->where('source', Ticket::SOURCE_QR)
            ->whereNull('reporter_anonymized_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Ticket::STATUS_CLOSED)->where('closed_at', '<', $before))
                ->orWhere(fn ($q) => $q->where('status', Ticket::STATUS_CANCELLED)->where('cancelled_at', '<', $before)))
            ->update([
                'contact_name' => __('service.reported.anonymized'),
                'contact_phone' => null,
                'contact_email' => null,
                'reporter_anonymized_at' => now(),
            ]);
    }
}
