<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\PublicTicketStatus;
use App\Modules\Service\Support\TicketNumber;

/**
 * The public "track my repair" search of the current company (no sign-in): by the exact ticket
 * number (TK001-2569-00001 or the old TK-2569-00001) or the exact serial number of the device.
 * A serial gives its open jobs, or else only the latest one — not the device's whole history.
 * Only the customer's view of each (PublicTicketStatus).
 */
class TrackTickets
{
    /**
     * @return list<array{ticket_no: string, state: string, step: int, updated_at: string, message: string|null}>
     */
    public function handle(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }

        $number = TicketNumber::parse($query);
        $tickets = $number
            ? Ticket::query()->where('ticket_no', $number['ticket_no'])->get()
            : $this->bySerial(mb_strtolower($query));

        return $tickets->map(fn (Ticket $ticket) => PublicTicketStatus::of($ticket))->values()->all();
    }

    private function bySerial(string $serial)
    {
        $all = Ticket::query()->whereRaw('lower(device_serial) = ?', [$serial])->latest('id')->limit(20)->get();
        $open = $all->filter(fn (Ticket $ticket) => ! in_array($ticket->status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED], true));

        return $open->isNotEmpty() ? $open : $all->take(1);
    }
}
