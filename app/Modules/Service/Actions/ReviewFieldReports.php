<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;

/**
 * The helpdesk has checked what came back through a ticket's links (pressed "checked", or moved
 * the job on): they no longer show as waiting.
 */
class ReviewFieldReports
{
    public function handle(Ticket $ticket, User $actor): int
    {
        return TicketFieldLink::query()->where('ticket_id', $ticket->id)->whereNotNull('submitted_at')->whereNull('reviewed_at')
            ->update(['reviewed_at' => now(), 'reviewed_by_name' => $actor->name]);
    }
}
