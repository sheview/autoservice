<?php

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\CrossTenant\ForwardedTickets;
use App\Modules\Service\Events\TicketStatusChanged;

/**
 * A ticket forwarded to us moved: the company that sent it sees it on its own ticket.
 */
class FollowForwardedTicket
{
    public function __construct(private ForwardedTickets $forwardedTickets) {}

    public function handle(TicketStatusChanged $event): void
    {
        $this->forwardedTickets->followUp($event->ticket, $event->actorName);
    }
}
