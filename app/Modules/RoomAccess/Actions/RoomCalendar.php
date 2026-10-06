<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomSchedule;
use App\Modules\Service\Actions\HolidaysBetween;
use Carbon\CarbonImmutable;

/**
 * A month of the rooms: who goes into which room on which day (each day of a standing request
 * shown on its own), the rooms' freeze periods, and the company's holidays. Requests the user may
 * not open show only that the room is taken then (no number, no names).
 */
class RoomCalendar
{
    /** What the calendar shows: still to happen, happening, and what happened. */
    public const STATUSES = [
        RoomAccessRequest::STATUS_PENDING, RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE,
        RoomAccessRequest::STATUS_EXITED, RoomAccessRequest::STATUS_OVERDUE,
    ];

    public function __construct(private Modules $modules) {}

    /**
     * @return array{from: string, to: string, entries: list<array<string, mixed>>, freezes: list<array<string, mixed>>, holidays: array<string, string>}
     */
    public function handle(User $user, CarbonImmutable $month, ?string $roomUlid = null): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $rooms = ServerRoom::query()->when($roomUlid, fn ($q) => $q->where('ulid', $roomUlid))->get()->keyBy('id');
        $customers = app(CustomerLabelNames::class)->handle();

        $entries = [];
        RoomAccessRequest::query()->whereIn('server_room_id', $rooms->keys())->whereIn('status', self::STATUSES)
            ->where('planned_start', '<=', $to)->where('planned_end', '>=', $from)
            ->orderBy('planned_start')->limit(2000)->get()
            ->each(function (RoomAccessRequest $request) use ($user, $from, $to, $rooms, $customers, &$entries) {
                $visible = $user->can('view', $request);
                $room = $rooms[$request->server_room_id] ?? null;
                foreach (RoomSchedule::slots($request, $from, $to) as [$start, $end]) {
                    $entries[] = [
                        'ulid' => $visible ? $request->ulid : null,
                        'request_no' => $visible ? $request->request_no : null,
                        'requester_name' => $visible ? $request->requester_name : null,
                        'status' => $request->status,
                        'recurring' => $request->isRecurring(),
                        'room' => $room?->name,
                        'room_ulid' => $room?->ulid,
                        'customer' => $customers[$request->customer_id] ?? '-',
                        'start' => $start->toIso8601String(),
                        'end' => $end->toIso8601String(),
                    ];
                }
            });
        usort($entries, fn (array $a, array $b) => strcmp($a['start'], $b['start']));

        $freezes = $rooms->flatMap(fn (ServerRoom $room) => collect($room->freeze_periods ?? [])
            ->filter(fn (array $p) => CarbonImmutable::parse($p['from'])->lte($to) && CarbonImmutable::parse($p['to'])->gte($from))
            ->map(fn (array $p) => ['room' => $room->name, 'from' => $p['from'], 'to' => $p['to'], 'reason' => $p['reason'] ?? null]))
            ->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'entries' => $entries,
            'freezes' => $freezes,
            'holidays' => $this->modules->enabled('service') ? app(HolidaysBetween::class)->handle($from, $to) : [],
        ];
    }
}
