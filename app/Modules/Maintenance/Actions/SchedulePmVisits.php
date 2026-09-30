<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Carbon\CarbonImmutable;

/**
 * Cuts the plan's period into rounds of interval_months and creates a scheduled PmVisit for each
 * round that has not already ended (a plan made mid-contract does not start out overdue).
 * A round is due on the last day of its period; the last round ends with the contract. A leftover
 * shorter than half a round (e.g. a contract "2026-01-15 to 2027-01-15") joins the round before it.
 *
 *   12-month contract from 2026-01-01, every 3 months:
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

    public function handle(PmPlan $plan): void
    {
        $today = today()->toDateString();

        foreach (self::periods($plan->starts_on->toDateString(), $plan->ends_on->toDateString(), $plan->interval_months) as $period) {
            if ($period['due_on'] < $today) {
                continue;
            }

            $plan->visits()->create($period + [
                'contract_id' => $plan->contract_id,
                'customer_id' => $plan->customer_id,
                'visit_no' => $this->generateNumber->handle(),
                'status' => PmVisit::STATUS_SCHEDULED,
                'assignee_id' => $plan->assignee_id,
            ]);
        }
    }
}
