<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts equipment on a work order that is still to do (draft, scheduled, in progress or sent
 * back), each with a copy of the checklist of its category. Only assets of the work order's
 * customer; those already on it are skipped.
 */
class AddPmVisitItems
{
    public function __construct(private AssetDetails $assetDetails, private LogPmEvent $logEvent) {}

    /**
     * @param  list<int>  $assetIds
     * @return int how many were added
     */
    public function handle(PmVisit $visit, array $assetIds, User $actor): int
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.not_open')]);
        }

        $assets = $this->assetDetails->handle(array_values(array_unique($assetIds)));
        if (count($assets) !== count(array_unique($assetIds))) {
            throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.asset_not_found')]);
        }
        foreach ($assets as $asset) {
            if ((int) $asset['customer_id'] !== (int) $visit->customer_id) {
                throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.asset_other_customer', ['code' => $asset['asset_code']])]);
            }
        }

        return DB::transaction(function () use ($visit, $assets, $actor) {
            $existing = $visit->items()->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
            $checklists = PmChecklist::query()->orderBy('id')->get();
            $added = [];

            foreach ($assets as $asset) {
                if (in_array((int) $asset['id'], $existing, true)) {
                    continue;
                }
                $checklist = ChecklistItems::pickFor($checklists, $asset['category_id']);
                $visit->items()->create([
                    'asset_id' => $asset['id'],
                    'pm_checklist_id' => $checklist?->id,
                    'checklist' => $checklist?->items ?? [],
                ]);
                $added[] = $asset['asset_code'];
            }

            if ($added !== []) {
                $this->logEvent->handle($visit, PmVisitEvent::TYPE_ITEM_ADDED, $actor, implode(', ', $added), null, null, ['assets' => $added]);
            }

            return count($added);
        });
    }
}
