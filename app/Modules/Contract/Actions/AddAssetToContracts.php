<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;

/**
 * Puts one asset into some contracts (for the Asset module: ticked when the asset is created).
 * The caller has checked the contracts are ones the user may change (EditableContracts);
 * AddContractAssets still refuses an asset of another customer.
 */
class AddAssetToContracts
{
    public function __construct(private AddContractAssets $addContractAssets) {}

    /**
     * @param  list<int>  $contractIds
     */
    public function handle(int $assetId, array $contractIds): void
    {
        foreach (Contract::query()->whereKey($contractIds)->get() as $contract) {
            $this->addContractAssets->handle($contract, [$assetId]);
        }
    }
}
