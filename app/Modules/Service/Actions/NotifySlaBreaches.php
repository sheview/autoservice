<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * For the current tenant: e-mails about running tickets whose response or resolve time just ran
 * out, once per ticket and clock (the *_breach_notified_at columns). Tickets on hold are skipped:
 * their resolve clock is stopped.
 */
class NotifySlaBreaches
{
    private const RUNNING = [Ticket::STATUS_NEW, Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS];

    public function __construct(private NotifyTicketEvent $notify) {}

    /**
     * @return int number of e-mails queued
     */
    public function handle(): int
    {
        $now = now();
        $count = 0;

        $response = Ticket::query()
            ->whereIn('status', self::RUNNING)
            ->whereNull('responded_at')
            ->whereNull('response_breach_notified_at')
            ->where('response_due_at', '<', $now)
            ->get();

        foreach ($response as $ticket) {
            $this->notify->handle($ticket, 'response_breached');
            $ticket->update(['response_breach_notified_at' => $now]);
            $count++;
        }

        $resolve = Ticket::query()
            ->whereIn('status', self::RUNNING)
            ->whereNull('resolve_breach_notified_at')
            ->where('resolve_due_at', '<', $now)
            ->get();

        foreach ($resolve as $ticket) {
            $this->notify->handle($ticket, 'resolve_breached');
            $ticket->update(['resolve_breach_notified_at' => $now]);
            $count++;
        }

        return $count;
    }
}
