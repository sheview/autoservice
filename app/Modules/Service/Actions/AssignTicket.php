<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use Illuminate\Validation\ValidationException;

/**
 * Gives an open ticket to a user (or takes it back with null). A "new" ticket becomes "assigned".
 */
class AssignTicket
{
    public function __construct(
        private RecordTicketEvent $recordEvent,
        private UserNames $userNames,
        private NotifyTicketEvent $notify,
    ) {}

    public function handle(Ticket $ticket, ?int $assigneeId, User $actor): Ticket
    {
        if (! in_array($ticket->status, Ticket::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['assignee_id' => __('service.tickets.not_open')]);
        }

        $from = $ticket->status;
        $ticket->assignee_id = $assigneeId;
        if ($assigneeId !== null && $ticket->status === Ticket::STATUS_NEW) {
            $ticket->status = Ticket::STATUS_ASSIGNED;
        } elseif ($assigneeId === null && $ticket->status === Ticket::STATUS_ASSIGNED) {
            $ticket->status = Ticket::STATUS_NEW;
        }

        if (! $ticket->isDirty()) {
            return $ticket;
        }

        $ticket->save();

        $this->recordEvent->handle($ticket, TicketEvent::TYPE_ASSIGNED, $actor, [
            'from_status' => $from,
            'to_status' => $ticket->status,
            'body' => $assigneeId ? ($this->userNames->handle([$assigneeId])[$assigneeId] ?? null) : null,
        ]);

        if ($assigneeId !== null) {
            $this->notify->handle($ticket, 'assigned', $actor);
        }

        return $ticket;
    }
}
