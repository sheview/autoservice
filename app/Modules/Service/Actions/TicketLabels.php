<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * Number and status of tickets by id (deleted ones too), for other modules showing a reference.
 */
class TicketLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, ticket_no: string, status: string}> keyed by ticket id
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Ticket::withTrashed()->whereKey($ids)->get(['id', 'ulid', 'ticket_no', 'status'])
            ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => $ticket->only(['ulid', 'ticket_no', 'status'])])
            ->all();
    }
}
