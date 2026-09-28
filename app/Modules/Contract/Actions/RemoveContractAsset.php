<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;

/**
 * Takes an asset out of a contract.
 */
class RemoveContractAsset
{
    public function handle(Contract $contract, int $assetId): void
    {
        $removed = $contract->contractAssets()->where('asset_id', $assetId)->delete();

        if ($removed > 0) {
            activity()->performedOn($contract)->event('asset_removed')
                ->withProperties(['asset_id' => $assetId])->log('นำทรัพย์สินออกจากสัญญา');
        }
    }
}
