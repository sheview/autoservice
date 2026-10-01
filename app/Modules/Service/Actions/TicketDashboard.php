<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TicketSlaState;

/**
 * The ticket figures of the home page, within what the user may see (SearchTickets), for the
 * Platform module's dashboard.
 */
class TicketDashboard
{
    public const RECENT = 6;

    public function __construct(private SearchTickets $search) {}

    /**
     * @return array{open: int, mine: int, unassigned: int, breached: int, due_soon: int,
     *     recent: list<array<string, mixed>>}
     */
    public function handle(User $user): array
    {
        $count = fn (array $filters) => $this->search->handle($user, $filters + ['status' => 'open'])->count();
        // Assigned to me while that is the user's job; otherwise everything open that they can see.
        $works = $user->customer_id === null && ! $user->can('tickets.assign');

        return [
            'open' => $count([]),
            'mine' => $count(['assignee' => 'me']),
            'unassigned' => $count(['assignee' => 'none']),
            'breached' => $count(['sla' => 'breached']),
            'due_soon' => $count(['sla' => 'due_soon']),
            'recent' => $this->search->handle($user, ['status' => 'open', 'sort' => 'created_at', 'direction' => 'desc'] + ($works ? ['assignee' => 'me'] : []))
                ->limit(self::RECENT)
                ->get()
                ->map(fn (Ticket $ticket) => [
                    ...$ticket->only(['ulid', 'ticket_no', 'title', 'status', 'priority']),
                    'sla' => TicketSlaState::of($ticket),
                    'created_at' => $ticket->created_at->toIso8601String(),
                ])->all(),
        ];
    }
}
