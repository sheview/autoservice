<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a request lets the team in: a one-off request its single period; a standing request
 * (recurrence) every chosen weekday of its period, from start_time to end_time.
 */
class RoomSchedule
{
    /** A standing request covers at most this many days. */
    public const MAX_DAYS = 366;

    /**
     * Each time slot of the request (from - to), within [$from, $to] when given.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public static function slots(RoomAccessRequest $request, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $start = CarbonImmutable::parse($request->planned_start);
        $end = CarbonImmutable::parse($request->planned_end);
        $rule = $request->recurrence;
        if (! $rule) {
            return ($from === null || $end->gt($from)) && ($to === null || $start->lt($to)) ? [[$start, $end]] : [];
        }

        $slots = [];
        $day = $start->startOfDay();
        $last = $end->startOfDay();
        if ($from !== null && $day->lt(CarbonImmutable::parse($from)->startOfDay())) {
            $day = CarbonImmutable::parse($from)->startOfDay();
        }
        if ($to !== null && $last->gt(CarbonImmutable::parse($to))) {
            $last = CarbonImmutable::parse($to)->startOfDay();
        }
        for (; $day->lte($last); $day = $day->addDay()) {
            if (in_array($day->isoWeekday(), $rule['weekdays'] ?? [], true)) {
                $slots[] = [self::at($day, $rule['start_time']), self::at($day, $rule['end_time'])];
            }
        }

        return $slots;
    }

    /** The slot the moment falls in (allowing to go in $earlyMinutes before it starts), or null. */
    public static function slotAt(RoomAccessRequest $request, CarbonInterface $moment, int $earlyMinutes = 0): ?array
    {
        foreach (self::slots($request, CarbonImmutable::parse($moment)->subDay(), CarbonImmutable::parse($moment)->addDay()) as [$from, $to]) {
            if ($moment->gte($from->subMinutes($earlyMinutes)) && $moment->lt($to)) {
                return [$from, $to];
            }
        }

        return null;
    }

    /** Whether any slot overlaps the period. */
    public static function overlaps(RoomAccessRequest $request, CarbonInterface $from, CarbonInterface $to): bool
    {
        foreach (self::slots($request, $from, $to) as [$start, $end]) {
            if ($start->lt($to) && $end->gt($from)) {
                return true;
            }
        }

        return false;
    }

    /** The days and hours of a standing request, as people read them: "จ. พ. ศ. 09:00-12:00". */
    public static function describe(RoomAccessRequest $request): string
    {
        $rule = $request->recurrence;
        if (! $rule) {
            return '';
        }
        $days = collect($rule['weekdays'] ?? [])->sort()->map(fn (int $d) => __("room_access.schedule.days.{$d}"))->implode(' ');

        return $days.' '.$rule['start_time'].'-'.$rule['end_time'];
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $day->setTime($h, $m);
    }
}
