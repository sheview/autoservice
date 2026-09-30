<?php

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period of a report: whole days, from the start of "from" to the end of "to" (Bangkok time).
 * Read from ?from=Y-m-d&to=Y-m-d; anything missing or unreadable falls back to this month so far.
 */
class ReportPeriod
{
    /** A report never covers more than this, so one request cannot read years of rows. */
    public const MAX_DAYS = 731;

    private function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    public static function fromRequest(Request $request): self
    {
        $today = CarbonImmutable::today();
        $from = self::date($request->input('from')) ?? $today->startOfMonth();
        $to = self::date($request->input('to')) ?? $today;

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return new self($from->startOfDay(), $to->endOfDay());
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        // checkdate: "2026-13-45" must not roll over into another month.
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3]);
    }
}
