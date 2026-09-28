<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSla;
use App\Modules\Service\Support\TicketWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a ticket along TicketWorkflow (start, hold, resolve, approve, reject, cancel) and keeps
 * the SLA clock right:
 *   - the first "start" is the response time (responded_at)
 *   - "hold" stops the resolve clock; leaving hold adds the business minutes spent on hold to
 *     hold_minutes and moves resolve_due_at
 *   - "reject" (the fix was not good enough) clears resolved_at; the resolve clock kept running
 */
class MoveTicket
{
    public function __construct(
        private TicketSla $sla,
        private RecordTicketEvent $recordEvent,
        private NotifyTicketEvent $notify,
    ) {}

    public function handle(Ticket $ticket, string $action, User $actor, ?string $comment = null): Ticket
    {
        if (! TicketWorkflow::allows($ticket, $action)) {
            throw ValidationException::withMessages(['action' => __('service.tickets.not_allowed')]);
        }
        if (in_array($action, TicketWorkflow::NEEDS_COMMENT, true) && blank($comment)) {
            throw ValidationException::withMessages(['comment' => __('service.tickets.reason_required')]);
        }

        return DB::transaction(function () use ($ticket, $action, $actor, $comment) {
            $now = now();
            $from = $ticket->status;

            if ($from === Ticket::STATUS_ON_HOLD && $ticket->on_hold_since !== null) {
                $ticket->hold_minutes += $this->sla->minutesBetween($ticket, $ticket->on_hold_since, $now);
                $ticket->on_hold_since = null;
                $this->sla->refreshDueDates($ticket);
            }

            $ticket->status = TicketWorkflow::ACTIONS[$action]['to'];

            match ($action) {
                'start' => $ticket->responded_at ??= $now,
                'hold' => $ticket->on_hold_since = $now,
                'resolve' => $ticket->resolved_at = $now,
                'approve' => $ticket->closed_at = $now,
                'reject' => $ticket->resolved_at = null,
                'cancel' => $ticket->cancelled_at = $now,
            };

            $ticket->save();

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_STATUS, $actor, [
                'from_status' => $from,
                'to_status' => $ticket->status,
                'body' => filled($comment) ? $comment : null,
            ]);

            // Ask whoever confirms the fix to check it.
            if ($action === 'resolve') {
                $this->notify->handle($ticket, 'resolved', $actor);
            }

            return $ticket;
        });
    }
}
