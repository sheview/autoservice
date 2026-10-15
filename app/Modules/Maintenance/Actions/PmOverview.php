<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Figures of PM work orders the user may see (pm-visits.view scope), for the PM dashboard and
 * the PM report page:
 *
 *   counts      by workflow stage now (not cancelled)
 *   period      of the work orders due between two days (optionally one customer): due, done
 *               (handed in), on time, overdue, cancelled, compliance %, results of the equipment
 *   trend       due and done per month, for the last N months
 *   customers   the period per customer
 *   plans       progress of each plan: rounds handed in of the rounds of the period due by today (or
 *               handed in ahead)
 *   failing     the equipment found failed most often within the period (top N)
 */
class PmOverview
{
    /** @return Builder<PmVisit> */
    public function visible(User $user): Builder
    {
        return SearchPmVisits::visibleTo(PmVisit::query(), $user);
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $today = today()->toDateString();
        $byStatus = $this->visible($user)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');

        return [
            ...collect(PmVisit::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($byStatus[$status] ?? 0)])->all(),
            'overdue' => $this->visible($user)->whereIn('status', PmVisit::OPEN_STATUSES)->where('due_on', '<', $today)->count(),
            'due_this_month' => $this->visible($user)->whereIn('status', PmVisit::OPEN_STATUSES)
                ->whereBetween('due_on', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])->count(),
            'mine' => $this->visible($user)->whereIn('status', PmVisit::OPEN_STATUSES)->where('assignee_id', $user->id)->count(),
        ];
    }

    /**
     * @return array{due: int, done: int, on_time: int, overdue: int, cancelled: int, compliance: int|null, results: array<string, int>}
     */
    public function period(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $customerId = null): array
    {
        $visits = $this->inPeriod($user, $from, $to, $customerId)->get(['id', 'status', 'due_on', 'completed_at']);
        $done = $visits->whereIn('status', PmVisit::DONE_STATUSES);
        $cancelled = $visits->where('status', PmVisit::STATUS_CANCELLED)->count();
        $toDo = $visits->count() - $cancelled;
        $results = PmVisitItem::query()->whereIn('pm_visit_id', $visits->modelKeys())
            ->groupBy('result')->selectRaw('result, count(*) as total')->pluck('total', 'result');

        return [
            'due' => $visits->count(),
            'done' => $done->count(),
            'on_time' => $done->filter(fn (PmVisit $visit) => $visit->completed_at?->toDateString() <= $visit->due_on->toDateString())->count(),
            'overdue' => $visits->filter(fn (PmVisit $visit) => $visit->isOverdue())->count(),
            'cancelled' => $cancelled,
            'compliance' => $toDo === 0 ? null : (int) round($done->count() * 100 / $toDo),
            'results' => collect([PmVisitItem::RESULT_PASSED, PmVisitItem::RESULT_FAILED, PmVisitItem::RESULT_NOT_INSPECTED, PmVisitItem::RESULT_PENDING])
                ->mapWithKeys(fn (string $result) => [$result => (int) ($results[$result] ?? 0)])->all(),
        ];
    }

    /**
     * @return list<array{month: string, due: int, done: int}>
     */
    public function trend(User $user, int $months = 12): array
    {
        $start = today()->toImmutable()->startOfMonth()->subMonths($months - 1);
        $rows = $this->visible($user)->where('status', '!=', PmVisit::STATUS_CANCELLED)
            ->where('due_on', '>=', $start->toDateString())->where('due_on', '<=', today()->endOfMonth()->toDateString())
            ->get(['status', 'due_on'])
            ->groupBy(fn (PmVisit $visit) => $visit->due_on->format('Y-m'));

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $rows) {
            $month = $start->addMonths($i)->format('Y-m');
            $visits = $rows[$month] ?? collect();

            return ['month' => $month, 'due' => $visits->count(), 'done' => $visits->whereIn('status', PmVisit::DONE_STATUSES)->count()];
        })->all();
    }

    /**
     * @return array<int, array{due: int, done: int, overdue: int, issues: int}> by customer id
     */
    public function customers(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $visits = $this->inPeriod($user, $from, $to, null)->where('status', '!=', PmVisit::STATUS_CANCELLED)
            ->withCount(['items as issue_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_FAILED)])
            ->get(['id', 'customer_id', 'status', 'due_on']);

        return $visits->groupBy('customer_id')->map(fn ($rows) => [
            'due' => $rows->count(),
            'done' => $rows->whereIn('status', PmVisit::DONE_STATUSES)->count(),
            'overdue' => $rows->filter(fn (PmVisit $visit) => $visit->isOverdue())->count(),
            'issues' => (int) $rows->sum('issue_count'),
        ])->all();
    }

    /**
     * Lowest progress first. Rounds of a revised work order count once: the original is cancelled,
     * the revision counts.
     *
     * @return list<array{plan_id: int, plan: string|null, due: int, done: int, progress: int}>
     */
    public function plans(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $until = $to->min(today()->toImmutable())->toDateString();
        $rows = $this->inPeriod($user, $from, $to, null)->whereNotNull('pm_plan_id')
            ->where('status', '!=', PmVisit::STATUS_CANCELLED)
            // due by today, or handed in already (ahead of its due date)
            ->where(fn (Builder $q) => $q->where('due_on', '<=', $until)->orWhereIn('status', PmVisit::DONE_STATUSES))
            ->with('plan:id,title')
            ->get(['id', 'pm_plan_id', 'status']);

        return $rows->groupBy('pm_plan_id')->map(function ($visits, $planId) {
            $done = $visits->whereIn('status', PmVisit::DONE_STATUSES)->count();

            return [
                'plan_id' => (int) $planId,
                'plan' => $visits->first()->plan?->title,
                'due' => $visits->count(),
                'done' => $done,
                'progress' => (int) round($done * 100 / $visits->count()),
            ];
        })->sortBy('progress')->values()->all();
    }

    /**
     * @return list<array{asset_id: int, asset_code: string|null, ulid: string|null, name: string|null, failed: int, last_on: string|null}>
     */
    public function failing(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $customerId = null, int $limit = 10): array
    {
        $rows = PmVisitItem::query()
            ->whereIn('pm_visit_id', $this->inPeriod($user, $from, $to, $customerId)
                ->where('status', '!=', PmVisit::STATUS_CANCELLED)->select('id'))
            ->where('result', PmVisitItem::RESULT_FAILED)
            ->groupBy('asset_id')
            ->selectRaw('asset_id, count(*) as failed, max(checked_at) as last_at')
            ->orderByDesc('failed')->orderByDesc('last_at')->orderBy('asset_id')
            ->limit($limit)->get();
        $assets = app(AssetDetails::class)->handle($rows->pluck('asset_id')->all());

        return $rows->map(fn ($row) => [
            'asset_id' => (int) $row->asset_id,
            'asset_code' => $assets[$row->asset_id]['asset_code'] ?? null,
            'ulid' => $assets[$row->asset_id]['ulid'] ?? null,
            'name' => $assets[$row->asset_id]['name'] ?? null,
            'failed' => (int) $row->failed,
            'last_on' => $row->last_at ? CarbonImmutable::parse($row->last_at)->toDateString() : null,
        ])->all();
    }

    /** @return Builder<PmVisit> */
    private function inPeriod(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $customerId): Builder
    {
        return $this->visible($user)
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->when($customerId, fn (Builder $q, $id) => $q->where('customer_id', $id));
    }
}
