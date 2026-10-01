<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use Illuminate\Support\Facades\DB;

/**
 * Records what the technician or helpdesk found about the device's warranty. Work cannot start
 * before this is done (MoveTicket); it may be checked again while the job is open.
 */
class CheckTicketWarranty
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    /**
     * @param  string  $status  one of Ticket::WARRANTY_STATUSES
     */
    public function handle(Ticket $ticket, User $actor, string $status, ?string $expiresOn): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $status, $expiresOn) {
            $ticket->update([
                'warranty_status' => $status,
                'warranty_expires_on' => $expiresOn,
                'warranty_checked_by_name' => $actor->name,
                'warranty_checked_at' => now(),
            ]);

            $body = __("service.warranty.{$status}");
            if ($expiresOn !== null) {
                $body .= ' ('.__('service.warranty.until', ['date' => $expiresOn]).')';
            }
            $this->recordEvent->handle($ticket, TicketEvent::TYPE_WARRANTY, $actor, ['body' => $body]);

            return $ticket;
        });
    }
}
