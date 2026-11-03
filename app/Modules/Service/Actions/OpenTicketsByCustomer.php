<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Open tickets per customer, of those the user may see (SearchTickets::visibleTo): how many,
 * and how many are past their resolve time (SLA breached). For the map's pins.
 */
class OpenTicketsByCustomer
{
    /**
     * @return array<int, array{open: int, overdue: int}> customer id => counts (customers with none are left out)
     */
    public function handle(User $user): array
    {
        return SearchTickets::visibleTo(Ticket::query(), $user)
            ->whereNotNull('customer_id')
            ->whereIn('status', Ticket::OPEN_STATUSES)
            ->groupBy('customer_id')
            ->select('customer_id', DB::raw('count(*) as open'), DB::raw('sum(case when resolve_due_at < ? then 1 else 0 end) as overdue'))
            ->addBinding(now(), 'select')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->customer_id => ['open' => (int) $row->open, 'overdue' => (int) $row->overdue]])
            ->all();
    }
}
