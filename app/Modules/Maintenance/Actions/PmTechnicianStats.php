<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Carbon\CarbonImmutable;

/**
 * The PM work of each technician over the work orders due within a period that the user may see
 * (not cancelled): assigned, handed in, on time (handed in by the due date, % of assigned),
 * equipment checked (passed or failed), failures found, and the average time on site (arrived →
 * left, minutes, of the visits that recorded both). Kept apart for the performance reviews.
 */
class PmTechnicianStats
{
    public function __construct(private PmOverview $overview, private UserNames $userNames) {}

    /**
     * @return list<array{assignee_id: int, name: string|null, assigned: int, done: int, on_time: int, on_time_rate: int|null, checked: int, failed: int, onsite_minutes: int|null}>
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $customerId = null): array
    {
        $visits = $this->overview->visible($user)
            ->whereNotNull('assignee_id')
            ->where('status', '!=', PmVisit::STATUS_CANCELLED)
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->withCount([
                'items as checked_count' => fn ($q) => $q->whereIn('result', [PmVisitItem::RESULT_PASSED, PmVisitItem::RESULT_FAILED]),
                'items as failed_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_FAILED),
            ])
            ->get(['id', 'assignee_id', 'status', 'due_on', 'completed_at', 'onsite_at', 'departed_at']);
        $names = $this->userNames->handle($visits->pluck('assignee_id')->unique()->values()->all());

        return $visits->groupBy('assignee_id')->map(function ($rows, $assigneeId) use ($names) {
            $done = $rows->whereIn('status', PmVisit::DONE_STATUSES);
            $onTime = $done->filter(fn (PmVisit $visit) => $visit->completed_at?->toDateString() <= $visit->due_on->toDateString())->count();
            $onsite = $rows->filter(fn (PmVisit $visit) => $visit->onsite_at && $visit->departed_at && $visit->departed_at->gte($visit->onsite_at))
                ->map(fn (PmVisit $visit) => $visit->onsite_at->diffInMinutes($visit->departed_at));

            return [
                'assignee_id' => (int) $assigneeId,
                'name' => $names[$assigneeId] ?? null,
                'assigned' => $rows->count(),
                'done' => $done->count(),
                'on_time' => $onTime,
                'on_time_rate' => (int) round($onTime * 100 / $rows->count()),
                'checked' => (int) $rows->sum('checked_count'),
                'failed' => (int) $rows->sum('failed_count'),
                'onsite_minutes' => $onsite->isEmpty() ? null : (int) round($onsite->avg()),
            ];
        })->sortByDesc('assigned')->values()->all();
    }
}
