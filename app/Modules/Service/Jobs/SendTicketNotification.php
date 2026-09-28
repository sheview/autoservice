<?php

namespace App\Modules\Service\Jobs;

use App\Modules\Identity\Actions\FindUsers;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Notifications\TicketNotification;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a TicketNotification to users of the tenant the job was dispatched in.
 */
class SendTicketNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public int $ticketId,
        public string $event,
        public array $userIds,
    ) {}

    public function handle(FindUsers $findUsers): void
    {
        $ticket = Ticket::find($this->ticketId);
        $users = $findUsers->handle($this->userIds);

        if ($ticket !== null && $users->isNotEmpty()) {
            Notification::sendNow($users, new TicketNotification($ticket, $this->event));
        }
    }
}
