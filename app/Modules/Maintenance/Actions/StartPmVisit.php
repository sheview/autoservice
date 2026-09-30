<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a round: one item per asset the contract covers now, each with a copy of the checklist
 * of its category (or the general checklist, the one without a category). Whoever starts an
 * unassigned round becomes its assignee.
 */
class StartPmVisit
{
    public function __construct(
        private ContractDetails $contractDetails,
        private AssetDetails $assetDetails,
    ) {}

    public function handle(PmVisit $visit, User $actor): PmVisit
    {
        if ($visit->status !== PmVisit::STATUS_SCHEDULED) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_allowed')]);
        }

        return DB::transaction(function () use ($visit, $actor) {
            $assetIds = $this->contractDetails->handle([$visit->contract_id])[$visit->contract_id]['asset_ids'] ?? [];
            $assets = $this->assetDetails->handle($assetIds);
            $checklists = PmChecklist::query()->orderBy('id')->get();
            $general = $checklists->firstWhere('asset_category_id', null);

            foreach ($assets as $asset) {
                $checklist = $checklists->firstWhere('asset_category_id', $asset['category_id']) ?? $general;
                $visit->items()->create([
                    'asset_id' => $asset['id'],
                    'pm_checklist_id' => $checklist?->id,
                    'checklist' => $checklist?->items ?? [],
                ]);
            }

            $visit->update([
                'status' => PmVisit::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'assignee_id' => $visit->assignee_id ?? $actor->id,
            ]);

            return $visit;
        });
    }
}
