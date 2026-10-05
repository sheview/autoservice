<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Jobs\SendCustomerTicketMail;
use App\Modules\Service\Models\Ticket;

/**
 * Tells the reporter of a ticket by e-mail (when they gave one) that it moved — queued after the
 * transaction commits.
 */
class NotifyCustomer
{
    public function handle(Ticket $ticket, string $event): void
    {
        if (filled($ticket->contact_email)) {
            SendCustomerTicketMail::dispatch($ticket->id, $event)->afterCommit();
        }
    }
}
