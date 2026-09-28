<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;

/**
 * Adds a comment to a ticket's timeline. Internal notes will be hidden from customer accounts.
 */
class CommentOnTicket
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    public function handle(Ticket $ticket, string $body, bool $internal, User $actor): TicketEvent
    {
        $ticket->touch();

        return $this->recordEvent->handle($ticket, TicketEvent::TYPE_COMMENT, $actor, [
            'body' => $body,
            'is_internal' => $internal,
        ]);
    }
}
