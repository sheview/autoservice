<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Service\Models\Ticket;

/**
 * Where tickets of the current company stand, as plain arrays, for a company that forwarded them
 * (Platform\CrossTenant): number, status, who works on it, when fixed.
 */
class TicketStatuses
{
    public function __construct(private UserNames $userNames) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, array{ticket_no: string, status: string, assignee: string|null, resolved_at: string|null}>
     */
    public function handle(array $ids): array
    {
        $tickets = Ticket::query()->whereKey($ids)->get();
        $names = $this->userNames->handle($tickets->pluck('assignee_id')->filter()->all());

        return $tickets->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => [
            'ticket_no' => $ticket->ticket_no,
            'status' => $ticket->status,
            'assignee' => $names[$ticket->assignee_id] ?? null,
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
        ]])->all();
    }
}
