<?php

namespace App\Modules\Service\Support;

use App\Modules\Service\Models\Ticket;

/**
 * The moves a ticket can make. Assigning is separate (AssignTicket): it can happen in any open
 * status and turns a "new" ticket into "assigned".
 *
 *   new ──assign──► assigned ──start──► in_progress ──resolve──► resolved ──approve──► closed
 *                                        │      ▲                   │
 *                                     hold│      │start/reject ◄─────┘
 *                                        ▼      │
 *                                        on_hold
 *   cancel: from any open status
 *
 * "ability" is the TicketPolicy method that decides who may do it.
 */
class TicketWorkflow
{
    /** @var array<string, array{from: list<string>, to: string, ability: string}> */
    public const ACTIONS = [
        'start' => ['from' => [Ticket::STATUS_ASSIGNED, Ticket::STATUS_ON_HOLD], 'to' => Ticket::STATUS_IN_PROGRESS, 'ability' => 'work'],
        'hold' => ['from' => [Ticket::STATUS_IN_PROGRESS], 'to' => Ticket::STATUS_ON_HOLD, 'ability' => 'work'],
        'resolve' => ['from' => [Ticket::STATUS_IN_PROGRESS], 'to' => Ticket::STATUS_RESOLVED, 'ability' => 'resolve'],
        'approve' => ['from' => [Ticket::STATUS_RESOLVED], 'to' => Ticket::STATUS_CLOSED, 'ability' => 'approve'],
        'reject' => ['from' => [Ticket::STATUS_RESOLVED], 'to' => Ticket::STATUS_IN_PROGRESS, 'ability' => 'approve'],
        'cancel' => ['from' => Ticket::OPEN_STATUSES, 'to' => Ticket::STATUS_CANCELLED, 'ability' => 'cancel'],
    ];

    /** Actions that need a reason in the comment box. */
    public const NEEDS_COMMENT = ['hold', 'reject', 'cancel'];

    public static function allows(Ticket $ticket, string $action): bool
    {
        return isset(self::ACTIONS[$action]) && in_array($ticket->status, self::ACTIONS[$action]['from'], true);
    }
}
