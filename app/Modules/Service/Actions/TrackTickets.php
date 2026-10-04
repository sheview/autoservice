<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;

/**
 * The public "track my repair" search (no sign-in): tickets of the current company by their exact
 * number or the exact serial number of the device. Exact only, so nobody can browse others' jobs;
 * and only what a customer may see: number, title, device, where the job is and when each step was
 * reached — never the customer, contact, notes or costs.
 */
class TrackTickets
{
    public const LIMIT = 10;

    /** The steps a job goes through, as shown to the public. */
    public const STEPS = ['new', 'assigned', 'in_progress', 'resolved', 'closed'];

    /**
     * @return list<array{ticket_no: string, title: string, device: string|null, status: string, step: int,
     *     state: string, at: array<string, string|null>}>
     */
    public function handle(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }
        $needle = mb_strtolower($query);

        return Ticket::query()
            ->where(fn ($q) => $q->whereRaw('lower(ticket_no) = ?', [$needle])->orWhereRaw('lower(device_serial) = ?', [$needle]))
            ->where('created_at', '>=', now()->subYear())
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Ticket $ticket) {
                $events = $ticket->events()->orderBy('id')->get(['type', 'to_status', 'created_at']);
                $firstTo = fn (string $status) => $events->first(fn (TicketEvent $e) => $e->to_status === $status)?->created_at;
                $step = match ($ticket->status) {
                    Ticket::STATUS_NEW => 0,
                    Ticket::STATUS_ASSIGNED => 1,
                    Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD => 2,
                    Ticket::STATUS_RESOLVED => 3,
                    Ticket::STATUS_CLOSED => 4,
                    // Cancelled: stopped where it had got to.
                    default => $ticket->resolved_at ? 3 : ($firstTo(Ticket::STATUS_IN_PROGRESS) ? 2 : ($ticket->assignee_id ? 1 : 0)),
                };

                return [
                    'ticket_no' => $ticket->ticket_no,
                    'title' => $ticket->title,
                    'device' => collect([$ticket->device_name, $ticket->device_brand, $ticket->device_model])->filter()->implode(' ') ?: null,
                    'status' => $ticket->status,
                    'step' => $step,
                    'state' => match ($ticket->status) {
                        Ticket::STATUS_CLOSED => 'done',
                        Ticket::STATUS_ON_HOLD => 'paused',
                        Ticket::STATUS_CANCELLED => 'cancelled',
                        default => 'active',
                    },
                    'at' => array_map(fn ($at) => $at?->timezone(config('app.timezone'))->toIso8601String(), [
                        'new' => $ticket->created_at,
                        'assigned' => $firstTo(Ticket::STATUS_ASSIGNED),
                        'in_progress' => $firstTo(Ticket::STATUS_IN_PROGRESS),
                        'resolved' => $ticket->resolved_at,
                        'closed' => $ticket->closed_at,
                    ]),
                ];
            })
            ->all();
    }
}
