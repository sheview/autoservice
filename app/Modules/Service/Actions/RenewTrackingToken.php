<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TrackingToken;

/**
 * A new tracking link for a ticket whose link got out: the old one stops working at once.
 */
class RenewTrackingToken
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    public function handle(Ticket $ticket, User $actor): Ticket
    {
        $ticket->forceFill(['tracking_token' => TrackingToken::make()])->save();
        $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, $actor, [
            'body' => __('service.tickets.tracking_renewed'),
            'is_internal' => true,
        ]);

        return $ticket;
    }
}
