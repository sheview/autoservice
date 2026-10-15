<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Support\Collection;

/**
 * The equipment under PM and where each piece stands: on a work order (other than a cancelled
 * one), or covered by the contract of a PM plan. For each: the last work handed in (its result),
 * and the next work order still to do (overdue past its due date).
 */
class PmEquipmentStatus
{
    public const FILTERS = ['overdue', 'due_soon', 'never'];

    /** "Due soon" = the next work order is due within this many days. */
    public const SOON_DAYS = 30;

    public function __construct(private ContractDetails $contractDetails) {}

    /**
     * @return list<int>
     */
    public function equipmentIds(): array
    {
        $onWorkOrders = PmVisitItem::query()
            ->whereHas('visit', fn ($q) => $q->where('status', '!=', PmVisit::STATUS_CANCELLED))
            ->distinct()->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
        $planned = collect($this->contractDetails->handle(PmPlan::query()->pluck('contract_id')->unique()->values()->all()))
            ->flatMap(fn (array $contract) => $contract['asset_ids'])->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$onWorkOrders, ...$planned]));
    }

    /**
     * @param  list<int>  $assetIds
     * @return array<int, array{last: array<string, mixed>|null, next: array<string, mixed>|null, done_count: int}> by asset id
     */
    public function handle(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $items = PmVisitItem::query()
            ->whereIn('asset_id', $assetIds)
            ->with('visit:id,ulid,visit_no,status,due_on,scheduled_on,completed_at,contract_id')
            ->get(['id', 'pm_visit_id', 'asset_id', 'result'])
            ->filter(fn (PmVisitItem $item) => $item->visit !== null)
            ->groupBy('asset_id');

        // Covered by a planned contract but not on a work order yet: the plan's next round counts.
        $planRounds = $this->openPlanRounds();
        $contractAssets = collect($this->contractDetails->handle(array_values(array_unique($planRounds->pluck('contract_id')->all()))))
            ->map(fn (array $contract) => $contract['asset_ids']);

        $status = [];
        foreach ($assetIds as $assetId) {
            /** @var Collection<int, PmVisitItem> $mine */
            $mine = $items[$assetId] ?? collect();
            $done = $mine->filter(fn (PmVisitItem $item) => in_array($item->visit->status, PmVisit::DONE_STATUSES, true))
                ->sortByDesc(fn (PmVisitItem $item) => $item->visit->completed_at);
            $last = $done->first();

            $open = $mine->map(fn (PmVisitItem $item) => $item->visit)->filter(fn (PmVisit $visit) => $visit->isOpen());
            $covering = $planRounds->filter(fn (PmVisit $visit) => in_array($assetId, $contractAssets[$visit->contract_id] ?? [], true));
            $next = $open->concat($covering)->sortBy(fn (PmVisit $visit) => ($visit->scheduled_on ?? $visit->due_on)->toDateString())->first();

            $status[$assetId] = [
                'last' => $last ? [
                    'visit_no' => $last->visit->visit_no,
                    'ulid' => $last->visit->ulid,
                    'date' => $last->visit->completed_at?->toDateString(),
                    'result' => $last->result,
                ] : null,
                'next' => $next ? [
                    'visit_no' => $next->visit_no,
                    'ulid' => $next->ulid,
                    'date' => ($next->scheduled_on ?? $next->due_on)->toDateString(),
                    'overdue' => $next->isOverdue(),
                ] : null,
                'done_count' => $done->count(),
            ];
        }

        return $status;
    }

    /**
     * Asset ids whose status matches a filter (FILTERS).
     *
     * @param  array<int, array<string, mixed>>  $status  from handle()
     * @return list<int>
     */
    public static function matching(array $status, string $filter): array
    {
        $soon = today()->addDays(self::SOON_DAYS)->toDateString();

        return array_keys(array_filter($status, fn (array $row) => match ($filter) {
            'overdue' => (bool) ($row['next']['overdue'] ?? false),
            'due_soon' => $row['next'] !== null && ! $row['next']['overdue'] && $row['next']['date'] <= $soon,
            'never' => $row['last'] === null,
            default => true,
        }));
    }

    /**
     * Rounds of plans still to do, not started yet (their equipment is taken from the contract then).
     *
     * @return Collection<int, PmVisit>
     */
    private function openPlanRounds(): Collection
    {
        return PmVisit::query()
            ->whereNotNull('pm_plan_id')
            ->whereIn('status', [PmVisit::STATUS_DRAFT, PmVisit::STATUS_SCHEDULED])
            ->whereDoesntHave('items')
            ->get(['id', 'ulid', 'visit_no', 'status', 'due_on', 'scheduled_on', 'contract_id']);
    }
}
