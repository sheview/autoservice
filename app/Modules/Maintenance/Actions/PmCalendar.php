<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Rounds of one month for the calendar, each on its day: the appointment date, or the due date
 * when there is none. Cancelled rounds are left out.
 */
class PmCalendar
{
    /**
     * @return array{month: string, customer_id: int|null, assignee: string|null}
     */
    public static function filtersFrom(Request $request): array
    {
        $month = (string) $request->input('month', '');
        $assignee = (string) $request->input('assignee', '');

        return [
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : today()->format('Y-m'),
            'customer_id' => $request->integer('customer_id') ?: null,
            'assignee' => in_array($assignee, ['me', 'none'], true) || ctype_digit($assignee) ? $assignee : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Collection<int, PmVisit> with "calendar_date" (Y-m-d) set
     */
    public function handle(User $user, array $filters): Collection
    {
        $first = CarbonImmutable::parse($filters['month'].'-01');
        $assignee = (string) ($filters['assignee'] ?? '');

        return SearchPmVisits::visibleTo(PmVisit::query(), $user)
            ->with('plan:id,title')
            ->where('status', '!=', PmVisit::STATUS_CANCELLED)
            ->whereRaw('coalesce(scheduled_on, due_on) between ? and ?', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($assignee === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when(ctype_digit($assignee), fn ($q) => $q->where('assignee_id', (int) $assignee))
            ->orderByRaw('coalesce(scheduled_on, due_on)')
            ->orderBy('visit_no')
            ->get()
            ->each(fn (PmVisit $visit) => $visit->setAttribute('calendar_date', ($visit->scheduled_on ?? $visit->due_on)->toDateString()));
    }
}
