<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Actions\FindUsers;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Service\Jobs\SendTicketNotification;
use App\Modules\Service\Models\Ticket;

/**
 * Decides who hears about a ticket event and queues the e-mail (after the transaction commits):
 *
 *   assigned           the assignee
 *   opened             dispatchers (tickets.assign) - used when a customer opens a ticket
 *   resolved           the reporter if it is a customer account, otherwise the approvers (tickets.approve)
 *   *_breached         the assignee and the dispatchers
 *
 * Staff chosen by permission only hear about tickets within the scope of that permission.
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
            'opened' => $this->staffWith($ticket, 'tickets.assign'),
            'resolved' => $this->customerReporter($ticket) ?? $this->staffWith($ticket, 'tickets.approve'),
            'response_breached', 'resolve_breached' => [$ticket->assignee_id, ...$this->staffWith($ticket, 'tickets.assign')],
        };

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null && $id !== $actor?->id)));

        if ($ids !== []) {
            SendTicketNotification::dispatch($ticket->id, $event, $ids)->afterCommit();
        }
    }

    /**
     * @return list<int>
     */
    private function staffWith(Ticket $ticket, string $permission): array
    {
        return $this->usersWithPermission->handle($permission)
            ->filter(fn (User $user) => DataScope::covers($ticket, $user, $permission,
                branch: fn (Ticket $t, ?int $branchId) => $t->branch_id === null || (int) $t->branch_id === (int) $branchId
                    || (int) $t->assignee_id === (int) $user->id,
                own: fn (Ticket $t) => (int) $t->reported_by === (int) $user->id || (int) $t->assignee_id === (int) $user->id))
            ->values()
            ->modelKeys();
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
