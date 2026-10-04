<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\LoansDueFor;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\PersonalEvent;
use App\Modules\Service\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * A person's "my work": the tickets they are assigned (on their appointment day, else when the
 * fix is due, else when opened), what they borrowed and must give back, and their own appointments
 * — on a month calendar, and as a to-do list of what is still open (overdue / today / this week / later).
 * Dates are days in the app's time zone (Y-m-d), so the page needs no time zone sums.
 */
class MyWork
{
    public function __construct(private LoansDueFor $loansDueFor, private Modules $modules) {}

    /**
     * @param  CarbonImmutable  $from  first day shown on the calendar
     * @param  CarbonImmutable  $to  last day shown on the calendar
     * @return array{events: list<array<string, mixed>>, todo: list<array<string, mixed>>}
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $day = fn ($at) => $at?->timezone($tz)->toDateString();
        $time = fn ($at) => $at?->timezone($tz)->format('H:i');

        $tickets = Ticket::query()
            ->where('assignee_id', $user->id)
            ->where('status', '!=', Ticket::STATUS_CANCELLED)
            ->where(fn ($q) => $q
                ->whereIn('status', Ticket::OPEN_STATUSES)
                ->orWhereBetween('appointment_at', [$from, $to->endOfDay()])
                ->orWhereBetween('resolve_due_at', [$from, $to->endOfDay()]))
            ->get()
            ->map(function (Ticket $ticket) use ($day, $time, $today) {
                $at = $ticket->appointment_at ?? $ticket->resolve_due_at ?? $ticket->created_at;
                $open = in_array($ticket->status, Ticket::OPEN_STATUSES, true);

                return [
                    'key' => "ticket-{$ticket->id}",
                    'kind' => 'ticket',
                    'title' => "{$ticket->ticket_no} {$ticket->title}",
                    'date' => $day($at),
                    'time' => $time($at),
                    'when' => $ticket->appointment_at ? 'appointment' : ($ticket->resolve_due_at ? 'due' : 'opened'),
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'open' => $open,
                    'overdue' => $open && $at->lt($today),
                    'href' => route('service.tickets.show', $ticket),
                ];
            });

        $loans = $this->modules->enabled('asset')
            ? collect($this->loansDueFor->handle($user->id, $to))->map(fn (array $loan) => [
                'key' => "loan-{$loan['id']}",
                'kind' => 'loan',
                'title' => "{$loan['item_name']} ({$loan['qty']} {$loan['unit']})",
                'date' => $loan['due'],
                'time' => null,
                'when' => 'return',
                'status' => null,
                'priority' => null,
                'open' => true,
                'overdue' => $loan['due'] < $today->toDateString(),
                'href' => route('asset.requests.show', $loan['request_ulid']),
            ])
            : collect();

        // Only the owner's: personal events are never read for anyone else.
        $personal = PersonalEvent::query()
            ->where('user_id', $user->id)
            ->where('starts_at', '<=', $to->endOfDay())
            ->where(fn ($q) => $q->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (PersonalEvent $event) => [
                'key' => "personal-{$event->id}",
                'kind' => 'personal',
                'id' => $event->id,
                'title' => $event->title,
                'date' => $day($event->starts_at),
                'end_date' => $day($event->ends_at),
                'time' => $event->all_day ? null : $time($event->starts_at),
                'end_time' => $event->all_day ? null : $time($event->ends_at),
                'all_day' => $event->all_day,
                'notes' => $event->notes,
                'when' => 'personal',
                'status' => null,
                'priority' => null,
                'open' => $event->starts_at->gte($today),
                'overdue' => false,
                'href' => null,
            ]);

        $all = $tickets->concat($loans)->concat($personal);
        $inRange = fn (array $e) => $e['date'] >= $from->toDateString() && $e['date'] <= $to->toDateString();

        return [
            'events' => $all->filter($inRange)->sortBy(fn (array $e) => $e['date'].' '.($e['time'] ?? '00:00'))->values()->all(),
            // Still to do, whatever the month shown: open tickets and loans, and coming appointments.
            'todo' => $all->filter(fn (array $e) => $e['open'] && ($e['kind'] !== 'personal' || $e['date'] >= $today->toDateString()))
                ->sortBy(fn (array $e) => $e['date'].' '.($e['time'] ?? '00:00'))
                ->values()
                ->all(),
        ];
    }
}
