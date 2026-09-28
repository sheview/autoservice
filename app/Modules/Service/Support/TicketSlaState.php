<?php

namespace App\Modules\Service\Support;

use App\Modules\Service\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Where a ticket stands against its SLA (not stored: it changes as time goes by).
 * Each clock is: none (no SLA) | pending | met | breached | paused (resolve clock on hold).
 */
class TicketSlaState
{
    /**
     * @return array{response: string, resolve: string}
     */
    public static function of(Ticket $ticket, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'response' => self::clock($ticket->response_due_at, $ticket->responded_at, $ticket, $now, false),
            'resolve' => self::clock($ticket->resolve_due_at, $ticket->resolved_at ?? $ticket->closed_at, $ticket, $now, true),
        ];
    }

    private static function clock(?CarbonInterface $due, ?CarbonInterface $doneAt, Ticket $ticket, CarbonInterface $now, bool $pausable): string
    {
        return match (true) {
            $due === null => 'none',
            $doneAt !== null => $doneAt->lte($due) ? 'met' : 'breached',
            $ticket->status === Ticket::STATUS_CANCELLED => 'none',
            $pausable && $ticket->status === Ticket::STATUS_ON_HOLD => 'paused',
            default => $now->gt($due) ? 'breached' : 'pending',
        };
    }
}
