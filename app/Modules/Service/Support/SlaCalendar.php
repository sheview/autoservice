<?php

namespace App\Modules\Service\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Business-time arithmetic for SLA clocks (Asia/Bangkok).
 *
 *   8x5   Monday–Friday   08:00–17:00, not on holidays
 *   12x6  Monday–Saturday 08:00–20:00, not on holidays
 *   24x7  all the time (holidays too)
 */
class SlaCalendar
{
    /** @var array<string, array{days: list<int>, start: int, end: int}> ISO weekdays, minutes after midnight */
    public const WINDOWS = [
        '8x5' => ['days' => [1, 2, 3, 4, 5], 'start' => 8 * 60, 'end' => 17 * 60],
        '12x6' => ['days' => [1, 2, 3, 4, 5, 6], 'start' => 8 * 60, 'end' => 20 * 60],
    ];

    /** Longest stretch searched for the next working day (a year of holidays would be a data error). */
    private const MAX_DAYS = 400;

    /** @var array<string, true> Y-m-d => true */
    private array $holidays;

    /**
     * @param  iterable<string>  $holidays  dates (Y-m-d) without service
     */
    public function __construct(iterable $holidays = [])
    {
        $this->holidays = [];
        foreach ($holidays as $date) {
            $this->holidays[$date] = true;
        }
    }

    /**
     * The moment $minutes of business time after $from.
     */
    public function addMinutes(CarbonInterface $from, int $minutes, string $window): CarbonImmutable
    {
        $cursor = $this->local($from);

        if ($window === '24x7') {
            return $cursor->addMinutes($minutes);
        }

        $hours = $this->hours($window);
        $cursor = $this->nextOpenMoment($cursor, $hours);

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            $closing = $cursor->startOfDay()->addMinutes($hours['end']);
            $available = (int) $cursor->diffInMinutes($closing);

            if ($minutes <= $available) {
                return $cursor->addMinutes($minutes);
            }

            $minutes -= $available;
            $cursor = $this->nextOpenMoment($closing->addDay()->startOfDay(), $hours);
        }

        throw new InvalidArgumentException('No working time found for the SLA.');
    }

    /**
     * Business minutes between two moments (0 when $to is not after $from).
     */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to, string $window): int
    {
        $from = $this->local($from);
        $to = $this->local($to);

        if ($to->lte($from)) {
            return 0;
        }

        if ($window === '24x7') {
            return (int) $from->diffInMinutes($to);
        }

        $hours = $this->hours($window);
        $minutes = 0;

        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            if (! $this->isWorkingDay($day, $hours)) {
                continue;
            }

            $open = $day->addMinutes($hours['start']);
            $close = $day->addMinutes($hours['end']);
            $start = $from->gt($open) ? $from : $open;
            $end = $to->lt($close) ? $to : $close;

            if ($end->gt($start)) {
                $minutes += (int) $start->diffInMinutes($end);
            }
        }

        return $minutes;
    }

    /**
     * $moment itself if it is inside working hours, otherwise the next opening time.
     *
     * @param  array{days: list<int>, start: int, end: int}  $hours
     */
    private function nextOpenMoment(CarbonImmutable $moment, array $hours): CarbonImmutable
    {
        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            $open = $moment->startOfDay()->addMinutes($hours['start']);
            $close = $moment->startOfDay()->addMinutes($hours['end']);

            if ($this->isWorkingDay($moment, $hours) && $moment->lt($close)) {
                return $moment->lt($open) ? $open : $moment;
            }

            $moment = $moment->addDay()->startOfDay();
        }

        throw new InvalidArgumentException('No working time found for the SLA.');
    }

    /**
     * @param  array{days: list<int>, start: int, end: int}  $hours
     */
    private function isWorkingDay(CarbonImmutable $day, array $hours): bool
    {
        return in_array($day->dayOfWeekIso, $hours['days'], true) && ! isset($this->holidays[$day->toDateString()]);
    }

    /**
     * @return array{days: list<int>, start: int, end: int}
     */
    private function hours(string $window): array
    {
        return self::WINDOWS[$window] ?? throw new InvalidArgumentException("Unknown service window {$window}.");
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('app.timezone'))->startOfMinute();
    }
}
