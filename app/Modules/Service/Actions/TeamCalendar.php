<?php

namespace App\Modules\Service\Actions;

use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\PmCalendar;
use App\Modules\Maintenance\Actions\PmProjectedRounds;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Platform\Support\Modules;
use App\Modules\RoomAccess\Actions\RoomCalendar;
use App\Modules\RoomAccess\Actions\RoomOptions;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use Carbon\CarbonImmutable;

/**
 * The other views of the one calendar on "งานของฉัน", besides the user's own work (MyWork):
 *
 *   pm    every PM work order the user may see (by appointment, else due day) and the rounds of
 *         the plans still to be made, by customer and technician (PmCalendar, PmProjectedRounds)
 *   room  who goes into which server room, with the rooms' freeze periods and the holidays (RoomCalendar)
 *
 * as the same items as MyWork's ("kind", "date", "title", "href"...), so one grid shows them all.
 * Each view only for who may see it, in a company that uses its module.
 */
class TeamCalendar
{
    public const VIEWS = ['mine', 'pm', 'room'];

    public function __construct(private Modules $modules) {}

    /**
     * The views the user may open, "mine" first.
     *
     * @return list<string>
     */
    public function views(User $user): array
    {
        return array_values(array_filter([
            'mine',
            $this->modules->enabled('maintenance') && $user->can('viewAny', PmVisit::class) ? 'pm' : null,
            $this->modules->enabled('room_access') && $user->can('viewAny', RoomAccessRequest::class) ? 'room' : null,
        ]));
    }

    /**
     * @param  array{customer_id?: int|null, assignee?: string|null, room?: string|null}  $filters
     * @return array{events: list<array<string, mixed>>, marks: array<string, array{holiday?: string, freeze?: string}>, options: array<string, mixed>}
     */
    public function handle(User $user, string $view, CarbonImmutable $month, array $filters): array
    {
        return match ($view) {
            'pm' => $this->pm($user, $month, $filters),
            'room' => $this->room($user, $month, $filters['room'] ?? null),
        };
    }

    private function pm(User $user, CarbonImmutable $month, array $filters): array
    {
        $pmFilters = ['month' => $month->format('Y-m'), 'customer_id' => $filters['customer_id'] ?? null, 'assignee' => $filters['assignee'] ?? null];
        $visits = app(PmCalendar::class)->handle($user, $pmFilters);
        $projected = app(PmProjectedRounds::class)->handle($user, $pmFilters);
        $customers = collect(app(ListCustomers::class)->handle(withTrashed: true))->pluck('name', 'id');
        $names = app(UserNames::class)->handle([...$visits->pluck('assignee_id')->all(), ...array_column($projected, 'assignee_id')]);
        $who = fn (?int $id) => $id ? ($names[$id] ?? null) : null;

        $events = $visits->map(fn (PmVisit $visit) => $this->item([
            'key' => "pmteam-{$visit->id}",
            'kind' => 'pm',
            'title' => trim($visit->visit_no.' '.($customers[$visit->customer_id] ?? $visit->plan?->title ?? '')),
            'date' => $visit->calendar_date,
            // Not given a day yet: on its due day.
            'when' => $visit->scheduled_on ? 'pm_appointment' : 'pm_due',
            'status' => $visit->status,
            'open' => $visit->isOpen(),
            'overdue' => $visit->isOverdue(),
            'notes' => implode(' · ', array_filter([$visit->plan?->title, $who($visit->assignee_id)])),
            'href' => route('maintenance.visits.show', $visit),
        ]))->values()->all();

        foreach ($projected as $round) {
            $events[] = $this->item([
                'key' => "pmround-{$round['plan_id']}-{$round['round']}",
                'kind' => 'pm_projected',
                'title' => trim(($customers[$round['customer_id']] ?? '').' · '.$round['plan'], ' ·'),
                'date' => $round['calendar_date'],
                'when' => 'pm_projected',
                'notes' => implode(' · ', array_filter([__('ui.my_work.creates_on', ['date' => $round['creates_on']]), $who($round['assignee_id'])])),
                'href' => route('maintenance.plans.show', $round['plan_id']),
            ]);
        }

        return [
            'events' => $events,
            'marks' => [],
            'options' => [
                'customers' => app(ListCustomers::class)->handle(),
                'assignees' => app(UsersWithPermission::class)->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values(),
            ],
        ];
    }

    private function room(User $user, CarbonImmutable $month, ?string $room): array
    {
        $calendar = app(RoomCalendar::class)->handle($user, $month, $room);
        $tz = config('app.timezone');

        $events = array_map(function (array $entry) use ($tz) {
            $start = CarbonImmutable::parse($entry['start'])->timezone($tz);
            $end = CarbonImmutable::parse($entry['end'])->timezone($tz);

            return $this->item([
                'key' => 'room-'.($entry['ulid'] ?? 'x').'-'.$start->format('YmdHi'),
                'kind' => 'room',
                // Someone else's request (not in the user's scope) only shows the room is taken.
                'title' => $entry['room'].' · '.($entry['request_no'] ? "{$entry['request_no']} {$entry['requester_name']}" : __('ui.my_work.room_taken')),
                'date' => $start->toDateString(),
                'end_date' => $end->toDateString() !== $start->toDateString() ? $end->toDateString() : null,
                'time' => $start->format('H:i'),
                'end_time' => $end->format('H:i'),
                'when' => 'room',
                'status' => $entry['status'],
                'notes' => $entry['customer'],
                'href' => $entry['ulid'] ? route('room-access.requests.show', $entry['ulid']) : null,
            ]);
        }, $calendar['entries']);

        // Days to stay out of the rooms: holidays, and the rooms' freeze periods (each day they cover).
        $marks = [];
        foreach ($calendar['holidays'] as $date => $name) {
            $marks[$date]['holiday'] = $name;
        }
        foreach ($calendar['freezes'] as $freeze) {
            $from = CarbonImmutable::parse($freeze['from'])->startOfDay();
            $to = CarbonImmutable::parse($freeze['to'])->startOfDay();
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $marks[$day->toDateString()]['freeze'] = trim($freeze['room'].' '.($freeze['reason'] ?? ''));
            }
        }

        return ['events' => $events, 'marks' => $marks, 'options' => ['rooms' => app(RoomOptions::class)->handle()]];
    }

    /**
     * An item as MyWork makes them.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        return $item + ['time' => null, 'end_date' => null, 'end_time' => null, 'status' => null, 'priority' => null, 'open' => false, 'overdue' => false, 'notes' => null, 'href' => null];
    }
}
