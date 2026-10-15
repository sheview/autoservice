<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\LoansDueFor;
use App\Modules\Contract\Actions\ContractMilestones;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\MyPmWork;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\PersonalEvent;
use App\Modules\Service\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * A person's "my work": the tickets they are assigned (on their appointment day, else when the
 * fix is due, else when opened), the PM work orders they are given (with how far along), what they borrowed and must give back, their own appointments,
 * and the days the contracts they may see start and end — on a month calendar, and as a to-do list of what is still open (overdue / today / this week / later).
 * Dates are days in the app's time zone (Y-m-d), so the page needs no time zone sums.
 */
class MyWork
{
    public function __construct(
        private LoansDueFor $loansDueFor,
        private ContractMilestones $contractMilestones,
        private MyPmWork $myPmWork,
        private Modules $modules,
    ) {}

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
            ->map(function (Ticket $ticket) use ($day, $time, $today, $user) {
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
                    // Straight to the one-page close while the job can still be closed there.
                    'close_href' => in_array($ticket->status, QuickCloseTicket::CLOSABLE, true) && $user->can('resolve', $ticket)
                        ? route('service.tickets.close', $ticket) : null,
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

        // The days the contracts they may see start and end: on the calendar only, nothing to do.
        $contracts = $this->modules->enabled('contract')
            ? collect($this->contractMilestones->handle($user, $from, $to))->map(fn (array $contract) => [
                'key' => "contract-{$contract['milestone']}-{$contract['id']}",
                'kind' => 'contract',
                'title' => $contract['contract_no'].' '.($contract['customer'] ?? $contract['title']),
                'date' => $contract['date'],
                'time' => null,
                'when' => "contract_{$contract['milestone']}",
                'status' => null,
                'priority' => null,
                'open' => false,
                'overdue' => false,
                'notes' => $contract['title'],
                'href' => $contract['href'],
            ])
            : collect();

        // PM work orders still to do, on their appointment (else due) day, with the equipment done so far.
        $pm = $this->modules->enabled('maintenance')
            ? collect($this->myPmWork->handle($user))->map(fn (array $visit) => [
                'key' => "pm-{$visit['id']}",
                'kind' => 'pm',
                'title' => trim($visit['visit_no'].' '.($visit['customer'] ?? $visit['title'] ?? '')),
                'date' => $visit['date'],
                'time' => null,
                'when' => 'pm',
                'status' => $visit['status'],
                'priority' => null,
                'open' => true,
                'overdue' => $visit['overdue'],
                'notes' => $visit['title'],
                'progress' => ['done' => $visit['done'], 'total' => $visit['items']],
                'href' => $visit['href'],
            ])
            : collect();

        $all = $tickets->concat($pm)->concat($loans)->concat($personal)->concat($contracts);
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
