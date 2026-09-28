<?php

namespace App\Modules\Service\Support;

use App\Modules\Service\Models\Holiday;
use App\Modules\Service\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Works out the SLA due times of a ticket from its copied SLA minutes, the service window and
 * the tenant's holidays:
 *
 *   response_due_at = created_at + response_minutes                 (business time)
 *   resolve_due_at  = created_at + resolve_minutes + hold_minutes    (business time)
 *
 * A ticket without SLA (out of contract, or no SLA for its priority) gets no due times.
 */
class TicketSla
{
    private ?SlaCalendar $calendar = null;

    public function refreshDueDates(Ticket $ticket): void
    {
        $window = $ticket->service_window;
        $start = $ticket->created_at ?? now();

        $ticket->response_due_at = $window && $ticket->response_minutes !== null
            ? $this->calendar()->addMinutes($start, $ticket->response_minutes, $window)
            : null;

        $ticket->resolve_due_at = $window && $ticket->resolve_minutes !== null
            ? $this->calendar()->addMinutes($start, $ticket->resolve_minutes + $ticket->hold_minutes, $window)
            : null;
    }

    /**
     * Business minutes from $from to $to in the ticket's service window (0 without one).
     */
    public function minutesBetween(Ticket $ticket, CarbonInterface $from, CarbonInterface $to): int
    {
        return $ticket->service_window ? $this->calendar()->minutesBetween($from, $to, $ticket->service_window) : 0;
    }

    private function calendar(): SlaCalendar
    {
        return $this->calendar ??= new SlaCalendar(
            Holiday::query()->pluck('date')->map(fn ($date) => $date->toDateString())
        );
    }
}
