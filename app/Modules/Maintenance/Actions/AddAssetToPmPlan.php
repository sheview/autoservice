<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Maintenance\Models\PmPlan;

/**
 * Puts an asset into a PM plan, so its later rounds check it too, with the plan's own form for it
 * when given (pm_plan_assets.template_code).
 *
 * A plan with no list covers every asset of its contract, now and later. Joining it lists them all
 * first, so none drops out of it — what an import into the plan does, the file being its list. With
 * $keepCoverage (equipment found on site), an asset already in that contract is covered as it is: the
 * plan stays without a list (listing it would drop what joins the contract later), and a form given
 * goes on the asset itself (AssignPmTemplateToAsset).
 */
class AddAssetToPmPlan
{
    public function __construct(
        private ContractDetails $contractDetails,
        private AssignPmTemplateToAsset $assignTemplate,
    ) {}

    public function handle(PmPlan $plan, int $assetId, ?string $templateCode = null, bool $keepCoverage = false): void
    {
        $templateCode = $templateCode === '' ? null : $templateCode;

        if (! $plan->assets()->exists()) {
            $contractAssets = array_map('intval', $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id]['asset_ids'] ?? []);
            if ($keepCoverage && in_array($assetId, $contractAssets, true)) {
                if ($templateCode !== null) {
                    $this->assignTemplate->handle($assetId, $templateCode);
                }

                return;
            }
            foreach ($contractAssets as $id) {
                $plan->assets()->create(['asset_id' => $id]);
            }
        }

        $row = $plan->assets()->firstOrCreate(['asset_id' => $assetId]);
        if ($templateCode !== null) {
            $row->update(['template_code' => $templateCode]);
        }
    }
}
