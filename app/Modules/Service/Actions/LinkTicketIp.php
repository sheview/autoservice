<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\IpChoice;
use App\Modules\Service\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Says which IP address a ticket is about (a choice from IpChoices), or none.
 */
class LinkTicketIp
{
    public function __construct(private IpChoice $ipChoice) {}

    public function handle(Ticket $ticket, ?string $choice): Ticket
    {
        $id = null;
        if ($choice !== null && $choice !== '') {
            $id = $this->ipChoice->handle($choice);
            if ($id === null) {
                throw ValidationException::withMessages(['ip' => __('service.tickets.ip_unknown')]);
            }
        }

        $ticket->update(['ip_address_id' => $id]);

        return $ticket;
    }
}
