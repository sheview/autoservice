<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cuts the plan's period into rounds of interval_months, and makes the work order of each round when
 * it comes near: a round gets its work order (scheduled, given the plan's technician) the
 * generate_days_ahead days before its period starts (PmSettings), never twice (the database keeps one
 * live work order per plan and round), and not at all once it has ended. Run when a plan is saved and
 * every day (GeneratePmRounds).
 *
 * A round is due on the last day of its period; the last round ends with the plan. A leftover shorter
 * than half a round (e.g. "2026-01-15 to 2027-01-15") joins the round before it.
 *
 *   12-month plan from 2026-01-01, every 3 months:
 *   round 1: 2026-01-01 – 2026-03-31, round 2: 2026-04-01 – 2026-06-30, ... round 4: ... – 2026-12-31
 */
class SchedulePmVisits
{
    public function __construct(private GeneratePmNumber $generateNumber) {}

    /**
     * @return list<array{round: int, period_starts_on: string, due_on: string}>
     */
    public static function periods(string $startsOn, string $endsOn, int $intervalMonths): array
    {
        $starts = CarbonImmutable::parse($startsOn);
        $ends = CarbonImmutable::parse($endsOn);
        $periods = [];

        for ($round = 1; ; $round++) {
            $from = $starts->addMonthsNoOverflow(($round - 1) * $intervalMonths);
            if ($from->gt($ends)) {
                break;
            }
            $to = $starts->addMonthsNoOverflow($round * $intervalMonths)->subDay();

            $leftoverDays = (int) $from->diffInDays($ends) + 1;
            if ($periods !== [] && $leftoverDays * 2 < (int) $from->diffInDays($to) + 1) {
                $periods[count($periods) - 1]['due_on'] = $ends->toDateString();
                break;
            }
            $periods[] = [
                'round' => $round,
                'period_starts_on' => $from->toDateString(),
                'due_on' => ($to->gt($ends) ? $ends : $to)->toDateString(),
            ];
        }

        return $periods;
    }

    /**
     * Makes the work orders of the rounds that have come near and have none yet.
     *
     * @return int how many were made
     */
    public function handle(PmPlan $plan, ?int $daysAhead = null): int
    {
        if (! $plan->active) {
            return 0;
        }

        $made = 0;
        foreach ($this->upcoming($plan, $daysAhead) as $period) {
            if ($period['creates_on'] > today()->toDateString()) {
                continue;
            }
            try {
                $plan->visits()->create([
                    'round' => $period['round'],
                    'period_starts_on' => $period['period_starts_on'],
                    'due_on' => $period['due_on'],
                    'contract_id' => $plan->contract_id,
                    'customer_id' => $plan->customer_id,
                    'visit_no' => $this->generateNumber->handle(),
                    'status' => PmVisit::STATUS_SCHEDULED,
                    'assignee_id' => $plan->assignee_id,
                ]);
                $made++;
            } catch (UniqueConstraintViolationException) {
                // Made meanwhile (two runs at once): that one stands.
            }
        }

        return $made;
    }

    /**
     * The rounds of the plan still to come that have no work order yet, with the day theirs will be
     * made (creates_on); rounds already ended are left out.
     *
     * @return list<array{round: int, period_starts_on: string, due_on: string, creates_on: string}>
     */
    public function upcoming(PmPlan $plan, ?int $daysAhead = null): array
    {
        $daysAhead ??= PmSettings::of()->generateDaysAhead;
        $today = today()->toDateString();
        $made = $plan->visits()->pluck('round')->map(fn ($round) => (int) $round)->all();

        $upcoming = [];
        foreach (self::periods($plan->starts_on->toDateString(), $plan->ends_on->toDateString(), $plan->interval_months) as $period) {
            if ($period['due_on'] < $today || in_array($period['round'], $made, true)) {
                continue;
            }
            $upcoming[] = $period + [
                'creates_on' => CarbonImmutable::parse($period['period_starts_on'])->subDays($daysAhead)->toDateString(),
            ];
        }

        return $upcoming;
    }
}
