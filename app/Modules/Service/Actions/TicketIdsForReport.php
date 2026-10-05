<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * Ids of the tickets of an MA contract and/or a customer (deleted ones too), for reports of other
 * modules that are filtered by them.
 */
class TicketIdsForReport
{
    /**
     * @return list<int>
     */
    public function handle(?int $contractId, ?int $customerId): array
    {
        return Ticket::withTrashed()
            ->when($contractId, fn ($q) => $q->where('contract_id', $contractId))
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
