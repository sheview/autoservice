<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TicketSlaState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ticket figures of the current tenant for a period, as plain arrays, for the Reporting module
 * (which must not use the Ticket model directly). "Opened in the period" is the base of every
 * breakdown; closed and cancelled count what happened in the period.
 */
class TicketReport
{
    /** Longer periods show the trend per month instead of per day. */
    public const DAILY_TREND_MAX_DAYS = 62;

    /**
     * @return array{opened: int, closed: int, cancelled: int, backlog: int, avg_resolve_hours: float|null,
     *     by_status: array<string, int>, by_priority: array<string, int>,
     *     sla: array<string, array{met: int, breached: int, pending: int, rate: int|null}>,
     *     trend: list<array{label: string, count: int}>, trend_unit: string,
     *     by_customer: array<int, int>, by_assignee: array<int, array{tickets: int, closed: int, resolve_breached: int}>,
     *     by_title: list<array{title: string, tickets: int, closed: int}>}
     */
    public function handle(CarbonInterface $from, CarbonInterface $to, ?int $customerId = null): array
    {
        // $customerId: only that customer's tickets (a customer account's report).
        $query = fn () => Ticket::query()->when($customerId, fn ($q, $id) => $q->where('customer_id', $id));

        $tickets = $query()
            ->whereBetween('created_at', [$from, $to])
            ->get([
                'id', 'status', 'priority', 'customer_id', 'assignee_id', 'created_at', 'title',
                'response_due_at', 'resolve_due_at', 'responded_at', 'resolved_at', 'closed_at',
            ]);

        $resolveSeconds = $query()
            ->whereBetween('resolved_at', [$from, $to])
            ->selectRaw('avg(timestampdiff(second, created_at, resolved_at)) as seconds')
            ->value('seconds');

        $states = $tickets->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => TicketSlaState::of($ticket)]);

        return [
            'opened' => $tickets->count(),
            'closed' => $query()->whereBetween('closed_at', [$from, $to])->count(),
            'cancelled' => $query()->whereBetween('cancelled_at', [$from, $to])->count(),
            // Everything still to be finished today, whenever it was opened.
            'backlog' => $query()->whereNotIn('status', [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED])->count(),
            'avg_resolve_hours' => $resolveSeconds === null ? null : round($resolveSeconds / 3600, 1),
            'by_status' => $this->countBy($tickets, 'status', Ticket::STATUSES),
            'by_priority' => $this->countBy($tickets, 'priority', Ticket::PRIORITIES),
            'sla' => [
                'response' => $this->sla($states->pluck('response')),
                'resolve' => $this->sla($states->pluck('resolve')),
            ],
            ...$this->trend($tickets, $from, $to),
            'by_customer' => $tickets->whereNotNull('customer_id')->countBy('customer_id')->sortDesc()->all(),
            'by_assignee' => $tickets->whereNotNull('assignee_id')->groupBy('assignee_id')->map(fn (Collection $own) => [
                'tickets' => $own->count(),
                'closed' => $own->where('status', Ticket::STATUS_CLOSED)->count(),
                'resolve_breached' => $own->filter(fn (Ticket $ticket) => $states[$ticket->id]['resolve'] === 'breached')->count(),
            ])->all(),
            // What goes wrong most: tickets by their topic (the same title, ignoring case and spaces).
            'by_title' => $tickets->groupBy(fn (Ticket $ticket) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $ticket->title))))
                ->filter(fn (Collection $same, string $key) => $key !== '')
                ->map(fn (Collection $same) => [
                    'title' => trim((string) $same->first()->title),
                    'tickets' => $same->count(),
                    'closed' => $same->where('status', Ticket::STATUS_CLOSED)->count(),
                ])
                ->sortByDesc('tickets')->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @param  list<string>  $values  every value gets a count, in this order
     * @return array<string, int>
     */
    private function countBy(Collection $tickets, string $column, array $values): array
    {
        $counts = $tickets->countBy($column);

        return collect($values)->mapWithKeys(fn (string $value) => [$value => (int) ($counts[$value] ?? 0)])->all();
    }

    /**
     * @param  Collection<int, string>  $states  one SLA clock of every ticket
     * @return array{met: int, breached: int, pending: int, rate: int|null} rate = % met of the clocks that are decided
     */
    private function sla(Collection $states): array
    {
        $met = $states->filter(fn (string $state) => $state === 'met')->count();
        $breached = $states->filter(fn (string $state) => $state === 'breached')->count();

        return [
            'met' => $met,
            'breached' => $breached,
            'pending' => $states->filter(fn (string $state) => in_array($state, ['pending', 'paused'], true))->count(),
            'rate' => $met + $breached === 0 ? null : (int) round($met * 100 / ($met + $breached)),
        ];
    }

    /**
     * Tickets opened per day, or per month for a long period; empty days and months are included.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array{trend: list<array{label: string, count: int}>, trend_unit: string}
     */
    private function trend(Collection $tickets, CarbonInterface $from, CarbonInterface $to): array
    {
        $daily = $from->diffInDays($to) <= self::DAILY_TREND_MAX_DAYS;
        $format = $daily ? 'Y-m-d' : 'Y-m';
        $counts = $tickets->countBy(fn (Ticket $ticket) => $ticket->created_at->format($format));

        $trend = [];
        $cursor = $daily ? $from->copy()->startOfDay() : $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $label = $cursor->format($format);
            $trend[] = ['label' => $label, 'count' => (int) ($counts[$label] ?? 0)];
            $cursor = $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return ['trend' => $trend, 'trend_unit' => $daily ? 'day' : 'month'];
    }
}
