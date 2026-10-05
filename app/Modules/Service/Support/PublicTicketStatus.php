<?php

namespace App\Modules\Service\Support;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use Illuminate\Support\Carbon;

/**
 * A ticket as a customer may see it, without signing in: where it stands in plain words, when that
 * last changed, and what the office wrote to them. Never who works on it, parts, costs or notes.
 * The one place that decides it (tracking by number / serial, the tracking link, the QR page).
 */
class PublicTicketStatus
{
    /** The steps shown, in order; "rejected" ends a ticket that was turned down or cancelled. */
    public const STEPS = ['reviewing', 'received', 'in_progress', 'done'];

    /**
     * @return array{ticket_no: string, state: string, step: int, updated_at: string, message: string|null}
     */
    public static function of(Ticket $ticket): array
    {
        $state = match ($ticket->status) {
            Ticket::STATUS_PENDING_REVIEW => 'reviewing',
            Ticket::STATUS_NEW, Ticket::STATUS_ASSIGNED => 'received',
            Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD => 'in_progress',
            Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED => 'done',
            default => 'rejected',
        };
        // When the job last moved (its status), not any edit of the office.
        $moved = $ticket->events()
            ->whereIn('type', [TicketEvent::TYPE_CREATED, TicketEvent::TYPE_STATUS, TicketEvent::TYPE_ASSIGNED])
            ->max('created_at');

        return [
            'ticket_no' => $ticket->ticket_no,
            'state' => $state,
            'step' => $state === 'rejected' ? -1 : array_search($state, self::STEPS, true),
            'updated_at' => ($moved ? Carbon::parse($moved) : $ticket->created_at)->toIso8601String(),
            'message' => $ticket->customer_message,
        ];
    }
}
