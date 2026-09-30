<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Carbon\CarbonInterface;

/**
 * PM figures of the current tenant for the rounds that fall due in a period, as plain arrays,
 * for the Reporting module (which must not use the PM models directly).
 */
class PmReport
{
    /**
     * @return array{due: int, completed: int, on_time: int, in_progress: int, overdue: int, scheduled: int,
     *     cancelled: int, compliance: int|null, items: array<string, int>}
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = PmVisit::query()
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'status', 'due_on', 'completed_at']);

        $completed = $visits->where('status', PmVisit::STATUS_COMPLETED);
        $cancelled = $visits->where('status', PmVisit::STATUS_CANCELLED)->count();
        $overdue = $visits->filter(fn (PmVisit $visit) => $visit->isOverdue())->count();
        $open = $visits->filter(fn (PmVisit $visit) => $visit->isOpen());
        $toDo = $visits->count() - $cancelled;

        $items = PmVisitItem::query()
            ->whereIn('pm_visit_id', $visits->modelKeys())
            ->groupBy('result')
            ->selectRaw('result, count(*) as total')
            ->pluck('total', 'result');

        return [
            'due' => $visits->count(),
            'completed' => $completed->count(),
            'on_time' => $completed->filter(fn (PmVisit $visit) => $visit->completed_at?->toDateString() <= $visit->due_on->toDateString())->count(),
            'in_progress' => $open->where('status', PmVisit::STATUS_IN_PROGRESS)->count(),
            'overdue' => $overdue,
            'scheduled' => $open->where('status', PmVisit::STATUS_SCHEDULED)->count(),
            'cancelled' => $cancelled,
            // Share of the rounds that were not cancelled and are done.
            'compliance' => $toDo === 0 ? null : (int) round($completed->count() * 100 / $toDo),
            'items' => collect([PmVisitItem::RESULT_OK, PmVisitItem::RESULT_ISSUE, PmVisitItem::RESULT_SKIPPED, PmVisitItem::RESULT_PENDING])
                ->mapWithKeys(fn (string $result) => [$result => (int) ($items[$result] ?? 0)])->all(),
        ];
    }
}
