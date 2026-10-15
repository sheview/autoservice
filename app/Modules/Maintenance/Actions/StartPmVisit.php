<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetChildIds;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a work order. Its equipment is what was listed on it beforehand (AddPmVisitItems); with
 * none listed, the equipment of its plan (pm_plan_assets), else every asset its contract covers
 * now — together with their parts (a PC's monitor: checked as a set, one row each; parts of another
 * customer are left out). Each item gets a copy of its template (PmTemplateResolver), a part the
 * one of its own kind. Whoever starts an unassigned work order becomes its technician.
 */
class StartPmVisit
{
    public function __construct(
        private ContractDetails $contractDetails,
        private AssetDetails $assetDetails,
        private AssetChildIds $childIds,
        private LogPmEvent $logEvent,
    ) {}

    public function handle(PmVisit $visit, User $actor): PmVisit
    {
        return DB::transaction(function () use ($visit, $actor) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! PmWorkflow::allows($visit, 'start')) {
                throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_allowed')]);
            }

            if (! $visit->items()->exists() && $visit->contract_id) {
                $assetIds = $visit->pm_plan_id ? PmPlanAsset::query()->where('pm_plan_id', $visit->pm_plan_id)->pluck('asset_id')->all() : [];
                if ($assetIds === []) {
                    $assetIds = $this->contractDetails->handle([$visit->contract_id])[$visit->contract_id]['asset_ids'] ?? [];
                }
                $assets = $this->assetDetails->handle($assetIds);
                $parts = array_filter(
                    $this->assetDetails->handle(array_diff($this->childIds->handle(array_keys($assets)), array_keys($assets))),
                    fn (array $part) => $part['customer_id'] === null || $part['customer_id'] === $visit->customer_id,
                );
                $templates = PmTemplateResolver::load($visit->pm_plan_id);
                foreach ($assets + $parts as $asset) {
                    $visit->items()->create(['asset_id' => $asset['id'], ...PmTemplateResolver::itemColumns($templates->for($asset))]);
                }
            }

            $from = $visit->status;
            $visit->update([
                'status' => PmVisit::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'assignee_id' => $visit->assignee_id ?? $actor->id,
            ]);
            $this->logEvent->handle($visit, 'start', $actor, null, $from, $visit->status, ['items' => $visit->items()->count()]);

            return $visit;
        });
    }
}
