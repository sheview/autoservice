<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Actions\FindUsers;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Jobs\SendTicketNotification;
use App\Modules\Service\Models\Ticket;

/**
 * Decides who hears about a ticket event and queues the e-mail (after the transaction commits):
 *
 *   assigned           the assignee
 *   opened             dispatchers (ticket.assign) - used when a customer opens a ticket
 *   resolved           the reporter if it is a customer account, otherwise the approvers (ticket.approve)
 *   *_breached         the assignee and the dispatchers
 *
 * The person who caused the event is never e-mailed about it.
 */
class NotifyTicketEvent
{
    public function __construct(
        private UsersWithPermission $usersWithPermission,
        private FindUsers $findUsers,
    ) {}

    public function handle(Ticket $ticket, string $event, ?User $actor = null): void
    {
        $ids = match ($event) {
            'assigned' => [$ticket->assignee_id],
            'opened' => $this->staffWith('ticket.assign'),
            'resolved' => $this->customerReporter($ticket) ?? $this->staffWith('ticket.approve'),
            'response_breached', 'resolve_breached' => [$ticket->assignee_id, ...$this->staffWith('ticket.assign')],
        };

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null && $id !== $actor?->id)));

        if ($ids !== []) {
            SendTicketNotification::dispatch($ticket->id, $event, $ids)->afterCommit();
        }
    }

    /**
     * @return list<int>
     */
    private function staffWith(string $permission): array
    {
        return $this->usersWithPermission->handle($permission)->modelKeys();
    }

    /**
     * @return list<int>|null the reporter, when it is an active customer account
     */
    private function customerReporter(Ticket $ticket): ?array
    {
        $reporter = $ticket->reported_by ? $this->findUsers->handle([$ticket->reported_by])->first() : null;

        return $reporter?->customer_id !== null ? [$reporter->id] : null;
    }
}
