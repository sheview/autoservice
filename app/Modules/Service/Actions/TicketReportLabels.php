<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * Tickets by id for report rows of other modules: number, link, and the customer, contract and
 * device they were for (ids, named by the caller through their modules).
 */
class TicketReportLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, ticket_no: string, customer_id: int|null, contract_id: int|null, asset_id: int|null}>
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Ticket::withTrashed()->whereKey($ids)->get(['id', 'tenant_id', 'ulid', 'ticket_no', 'customer_id', 'contract_id', 'asset_id'])
            ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => [
                ...$ticket->only(['ulid', 'ticket_no', 'customer_id', 'contract_id', 'asset_id']),
            ]])
            ->all();
    }
}
