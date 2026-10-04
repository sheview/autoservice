<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;

/**
 * When the technician is due on site for the job (or none): it puts the job on that day of the
 * assignee's "my work" calendar, and is written to the ticket's history.
 */
class SetTicketAppointment
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    public function handle(Ticket $ticket, ?string $at, User $actor): Ticket
    {
        $ticket->appointment_at = $at;
        if ($ticket->isDirty('appointment_at')) {
            $ticket->save();
            $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, $actor, [
                'body' => $ticket->appointment_at
                    ? __('service.tickets.appointment_set', ['at' => $ticket->appointment_at->timezone(config('app.timezone'))->format('d/m/Y H:i')])
                    : __('service.tickets.appointment_cleared'),
            ]);
        }

        return $ticket;
    }
}
