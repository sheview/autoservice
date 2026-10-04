<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;

/**
 * An internal note on a ticket of the current company written by the system, e.g. what became of
 * the job at the company it was forwarded to (Platform\CrossTenant).
 */
class NoteOnTicket
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    public function handle(int $ticketId, string $body): void
    {
        $ticket = Ticket::find($ticketId);
        if ($ticket !== null) {
            $this->recordEvent->handle($ticket, TicketEvent::TYPE_COMMENT, null, ['body' => $body, 'is_internal' => true]);
        }
    }
}
