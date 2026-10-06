<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomSchedule;
use App\Modules\Service\Actions\HolidaysBetween;
use Carbon\CarbonImmutable;

/**
 * What to look out for when booking a room for a time (warnings; nothing here blocks except the
 * freeze, which SubmitRoomAccessRequest refuses): other requests for the room at the same time
 * (pending, approved or inside), the room's freeze periods, and the company's holidays. Another
 * request shows its number and requester only when the user may see it; otherwise only that the
 * room is taken then.
 */
class RoomClashes
{
    public const BUSY_STATUSES = [RoomAccessRequest::STATUS_PENDING, RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE];

    public function __construct(private Modules $modules) {}

    /**
     * $request: the times to check (saved or not); its own row is left out.
     *
     * @return array{requests: list<array<string, mixed>>, freezes: list<array<string, string|null>>, holidays: list<array{date: string, name: string}>}
     */
    public function handle(RoomAccessRequest $request, User $user): array
    {
        $from = CarbonImmutable::parse($request->planned_start);
        $to = CarbonImmutable::parse($request->planned_end);
        $room = ServerRoom::withTrashed()->find($request->server_room_id);

        $requests = RoomAccessRequest::query()->where('server_room_id', $request->server_room_id)
            ->whereIn('status', self::BUSY_STATUSES)
            ->when($request->exists, fn ($q) => $q->whereKeyNot($request->id))
            ->where('planned_start', '<', $to)->where('planned_end', '>', $from)
            ->orderBy('planned_start')->limit(200)->get()
            ->filter(fn (RoomAccessRequest $other) => $this->clashes($request, $other))
            ->map(fn (RoomAccessRequest $other) => $user->can('view', $other) ? [
                'ulid' => $other->ulid,
                'request_no' => $other->request_no,
                'requester_name' => $other->requester_name,
                'status' => $other->status,
                'planned_start' => $other->planned_start->toIso8601String(),
                'planned_end' => $other->planned_end->toIso8601String(),
                'schedule' => RoomSchedule::describe($other) ?: null,
            ] : [
                'ulid' => null, 'request_no' => null, 'requester_name' => null, 'status' => $other->status,
                'planned_start' => $other->planned_start->toIso8601String(),
                'planned_end' => $other->planned_end->toIso8601String(),
                'schedule' => RoomSchedule::describe($other) ?: null,
            ])->values()->all();

        $freezes = collect($room?->freeze_periods ?? [])
            ->filter(fn (array $p) => RoomSchedule::overlaps($request, CarbonImmutable::parse($p['from']), CarbonImmutable::parse($p['to'])))
            ->map(fn (array $p) => ['from' => $p['from'], 'to' => $p['to'], 'reason' => $p['reason'] ?? null])->values()->all();

        // Holidays on the days the team would go in.
        $days = collect(RoomSchedule::slots($request))->map(fn (array $slot) => $slot[0]->toDateString())->flip();
        $holidays = $this->modules->enabled('service') && $from->diffInDays($to) <= RoomSchedule::MAX_DAYS + 1
            ? collect(app(HolidaysBetween::class)->handle($from, $to))
                ->filter(fn (string $name, string $date) => $request->isRecurring() ? $days->has($date) : true)
                ->map(fn (string $name, string $date) => ['date' => $date, 'name' => $name])->values()->all()
            : [];

        return ['requests' => $requests, 'freezes' => $freezes, 'holidays' => $holidays];
    }

    /** Whether two requests for the room have a moment in common (day by day for standing ones). */
    private function clashes(RoomAccessRequest $a, RoomAccessRequest $b): bool
    {
        foreach (RoomSchedule::slots($a, $b->planned_start, $b->planned_end) as [$from, $to]) {
            if (RoomSchedule::overlaps($b, $from, $to)) {
                return true;
            }
        }

        return false;
    }
}
