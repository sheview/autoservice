<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmPlan;
use Carbon\CarbonImmutable;

/**
 * Rounds of active plans whose work orders are not made yet (they are made ahead by the daily run),
 * on their due date within a month, for the calendar — of the plans the user may see, with the
 * calendar's customer / technician filters.
 */
class PmProjectedRounds
{
    public function __construct(private SchedulePmVisits $schedule) {}

    /**
     * @param  array{month: string, customer_id?: int|null, assignee?: string|null}  $filters  PmCalendar::filtersFrom()
     * @return list<array{plan_id: int, plan: string, customer_id: int, assignee_id: int|null, round: int, calendar_date: string, creates_on: string}>
     */
    public function handle(User $user, array $filters): array
    {
        $first = CarbonImmutable::parse($filters['month'].'-01');
        $last = $first->endOfMonth()->toDateString();
        $assignee = (string) ($filters['assignee'] ?? '');

        $plans = SearchPmPlans::visibleTo(PmPlan::query(), $user)
            ->where('active', true)
            ->where('ends_on', '>=', $first->toDateString())
            ->where('starts_on', '<=', $last)
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($assignee === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when(ctype_digit($assignee), fn ($q) => $q->where('assignee_id', (int) $assignee))
            ->get();

        $rounds = [];
        foreach ($plans as $plan) {
            foreach ($this->schedule->upcoming($plan) as $round) {
                if ($round['due_on'] >= $first->toDateString() && $round['due_on'] <= $last) {
                    $rounds[] = [
                        'plan_id' => $plan->id,
                        'plan' => $plan->title,
                        'customer_id' => $plan->customer_id,
                        'assignee_id' => $plan->assignee_id,
                        'round' => $round['round'],
                        'calendar_date' => $round['due_on'],
                        'creates_on' => $round['creates_on'],
                    ];
                }
            }
        }

        return $rounds;
    }
}
