<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;

/**
 * Open tickets the user may see, as plain arrays, for the issue/loan request form (Asset module):
 * the parts asked for are used on one of them, so their cost goes to that job.
 */
class TicketsForCheckout
{
    public const LIMIT = 30;

    /**
     * @param  list<int>|null  $ids  only these (checking that the user may see them); null = search the open ones
     * @return array<int, array{id: int, ulid: string, ticket_no: string, title: string, customer_id: int|null}> keyed by id
     */
    public function handle(User $user, ?array $ids = null, string $search = ''): array
    {
        return SearchTickets::visibleTo(Ticket::query(), $user)
            ->when($ids !== null, fn ($q) => $q->whereKey($ids), fn ($q) => $q
                ->whereIn('status', Ticket::OPEN_STATUSES)
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('ticket_no', 'ilike', "%{$search}%")
                    ->orWhere('title', 'ilike', "%{$search}%")))
                ->latest('id')
                ->limit(self::LIMIT))
            ->get(['id', 'ulid', 'ticket_no', 'title', 'customer_id'])
            ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => $ticket->only(['id', 'ulid', 'ticket_no', 'title', 'customer_id'])])
            ->all();
    }
}
