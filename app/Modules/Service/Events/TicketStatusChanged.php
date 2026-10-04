<?php

namespace App\Modules\Service\Events;

use App\Modules\Service\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket moved on its workflow (MoveTicket), e.g. for a company that forwarded it to follow.
 */
class TicketStatusChanged
{
    use Dispatchable;

    public function __construct(public Ticket $ticket, public string $from, public ?string $actorName) {}
}
