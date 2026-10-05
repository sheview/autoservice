<?php

namespace App\Modules\Service\Jobs;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Notifications\CustomerTicketMail;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a CustomerTicketMail to the e-mail the reporter gave, in the tenant the job was dispatched in.
 * Nothing goes once the reporter's details were blanked out.
 */
class SendCustomerTicketMail implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function __construct(public int $ticketId, public string $event) {}

    public function handle(): void
    {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket !== null && filled($ticket->contact_email) && $ticket->reporter_anonymized_at === null) {
            Notification::route('mail', $ticket->contact_email)->notifyNow(new CustomerTicketMail($ticket, $this->event));
        }
    }
}
