<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\TicketFieldLink;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * The repair fee claim form an outside technician (out of town) prints, fills in by hand and posts
 * back with the documents to be paid: who claims (name and company of the link already written
 * in), the repairs (this ticket on the first line), the documents to attach and the payment terms.
 * With the company's logo and name.
 */
class RepairClaimSheet
{
    /** Lines for the repairs, the first one this ticket. */
    public const ROWS = 6;

    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function handle(TicketFieldLink $link): array
    {
        $tenant = $this->context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);
        $ticket = $link->ticket;

        return [
            'company' => $tenant?->name,
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'claimant' => [
                'name' => $link->holder_name,
                'company' => $link->holder_company,
                'phone' => $link->holder_phone,
            ],
            'ticket' => $ticket ? trim($ticket->ticket_no.' '.$ticket->title) : null,
            'rows' => self::ROWS,
        ];
    }
}
