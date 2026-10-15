<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlan;

/**
 * Sets the equipment a plan covers (pm_plan_assets): the assets given (already checked to be the
 * contract's); none = every asset of the contract. A template chosen for an asset that stays is kept.
 */
class SyncPmPlanAssets
{
    /**
     * @param  list<int>  $assetIds
     */
    public function handle(PmPlan $plan, array $assetIds): void
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $plan->assets()->whereNotIn('asset_id', $assetIds ?: [0])->delete();

        $kept = $plan->assets()->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff($assetIds, $kept) as $assetId) {
            $plan->assets()->create(['asset_id' => $assetId]);
        }
    }
}
