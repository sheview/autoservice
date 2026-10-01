<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;

/**
 * The customers the user works for through their own tickets (reported by them or assigned to
 * them): what scope "own" of customers and contracts reaches (Contract module).
 */
class TicketCustomerIds
{
    /**
     * @return list<int>
     */
    public function handle(User $user): array
    {
        return Ticket::query()
            ->whereNotNull('customer_id')
            ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere('reported_by', $user->id))
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
