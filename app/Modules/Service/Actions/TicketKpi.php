<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ticket work per person over a year (or a month of it), for the people summary (Reporting):
 *   opened    tickets the person keyed in (reported_by), by created_at
 *   resolved  tickets the person fixed (assignee, resolved or closed), by resolved_at
 *   on_time   of those, fixed by their resolve due time (tickets with a due time only)
 *   with_due  of those, how many had a due time (the base of the on-time rate)
 *   avg_hours average hours from opening to fixed
 * Cancelled tickets count as opened but never as fixed. Years run in the app's time zone.
 */
class TicketKpi
{
    /**
     * @param  list<int>|null  $userIds  only these people; null = everyone with work in the period
     * @param  int|null  $month  1-12 for one month of the year; null = the whole year, by person
     * @return array<int, array{opened: int, resolved: int, on_time: int, with_due: int, avg_hours: float|null}> user id =>
     *                                                                                                           figures; with $byMonth, month (1-12) => figures of the one user given
     */
    public function handle(int $year, ?array $userIds = null, bool $byMonth = false): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));
        $to = $from->addYear();
        $monthOf = fn (string $column) => "extract(month from {$column} at time zone '".config('app.timezone')."')::int";

        $opened = Ticket::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('reported_by')
            ->when($userIds !== null, fn ($q) => $q->whereIn('reported_by', $userIds))
            ->selectRaw(($byMonth ? $monthOf('created_at') : 'reported_by').' as k, count(*) as opened')
            ->groupBy('k')
            ->pluck('opened', 'k');

        $resolved = Ticket::query()
            ->whereBetween('resolved_at', [$from, $to])
            ->whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->whereNotNull('assignee_id')
            ->when($userIds !== null, fn ($q) => $q->whereIn('assignee_id', $userIds))
            ->select(DB::raw(($byMonth ? $monthOf('resolved_at') : 'assignee_id').' as k'))
            ->selectRaw('count(*) as resolved')
            ->selectRaw('count(*) filter (where resolve_due_at is not null and resolved_at <= resolve_due_at) as on_time')
            ->selectRaw('count(*) filter (where resolve_due_at is not null) as with_due')
            ->selectRaw('avg(extract(epoch from resolved_at - created_at) / 3600) as avg_hours')
            ->groupBy('k')
            ->get()
            ->keyBy('k');

        $keys = $byMonth ? range(1, 12) : $opened->keys()->merge($resolved->keys())->unique()->values()->all();

        $figures = [];
        foreach ($keys as $key) {
            $row = $resolved->get($key);
            $figures[(int) $key] = [
                'opened' => (int) ($opened[$key] ?? 0),
                'resolved' => (int) ($row->resolved ?? 0),
                'on_time' => (int) ($row->on_time ?? 0),
                'with_due' => (int) ($row->with_due ?? 0),
                'avg_hours' => $row?->avg_hours !== null ? round((float) $row->avg_hours, 1) : null,
            ];
        }

        return $figures;
    }
}
