<?php

namespace App\Modules\Service\Actions;

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * An empty job sheet to fill in by hand away from the system (no signal on site, or a customer
 * who signs on paper): the same document as TicketSheet, with the company's logo and name, but
 * every value left blank and the choices as boxes to tick. It has no ticket number: the system
 * gives one when the job is keyed in, and staff write it on the paper then.
 */
class BlankTicketSheet
{
    /** How the customer told us, offered as boxes: the ones that happen away from the system. */
    public const SOURCES = ['phone', 'email', 'walk_in'];

    public function __construct(private TenantContext $context, private Modules $modules) {}

    /**
     * @return array<string, mixed> the shape of TicketSheet::handle(), plus blank_form choices
     */
    public function handle(): array
    {
        $tenant = $this->context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        return [
            'blank_form' => [
                'sources' => self::SOURCES,
                'priorities' => Ticket::PRIORITIES,
            ],
            'company' => $tenant?->name,
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'tracking' => null,
            'ticket' => [
                'ticket_no' => null,
                'title' => null,
                'description' => null,
                'status' => null,
                'priority' => null,
                'source' => null,
                'contact_name' => null,
                'contact_phone' => null,
                'customer' => null,
                'asset' => null,
                'device' => [
                    'registered' => false, 'name' => null, 'brand' => null, 'model' => null, 'serial' => null,
                    'serial_unknown' => false, 'location' => null, 'ip' => null, 'property_no' => null,
                ],
                'warranty' => ['status' => null, 'expires_on' => null],
                'report' => ['cause' => null, 'extra_cost' => null, 'approver_name' => null],
                'department' => null,
                'contract_no' => null,
                'branch' => null,
                'assignee' => null,
                'reporter' => null,
                'created_at' => null,
                'responded_at' => null,
                'resolved_at' => null,
                'closed_at' => null,
            ],
            'notes' => [],
            // Empty rows to write the parts in; none without the stock module.
            'parts' => $this->modules->enabled('inventory') ? [] : null,
            'signature' => null,
            'rating' => $this->modules->enabled('survey') ? ['score' => null, 'comment' => null] : null,
        ];
    }
}
