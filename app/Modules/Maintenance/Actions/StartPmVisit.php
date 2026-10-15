<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a work order. Its equipment is what was listed on it beforehand (AddPmVisitItems); with
 * none listed, every asset its contract covers now. Each item gets a copy of the checklist of its
 * category (or the general checklist, the one without a category). Whoever starts an unassigned
 * work order becomes its technician.
 */
class StartPmVisit
{
    public function __construct(
        private ContractDetails $contractDetails,
        private AssetDetails $assetDetails,
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
                $assetIds = $this->contractDetails->handle([$visit->contract_id])[$visit->contract_id]['asset_ids'] ?? [];
                $checklists = PmChecklist::query()->orderBy('id')->get();
                foreach ($this->assetDetails->handle($assetIds) as $asset) {
                    $checklist = ChecklistItems::pickFor($checklists, $asset['category_id']);
                    $visit->items()->create([
                        'asset_id' => $asset['id'],
                        'pm_checklist_id' => $checklist?->id,
                        'checklist' => $checklist?->items ?? [],
                    ]);
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
