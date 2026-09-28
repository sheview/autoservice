<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds assets to a contract. Every asset must belong to the contract's customer;
 * assets already in the contract are skipped.
 */
class AddContractAssets
{
    public function __construct(private AssetDetails $assetDetails) {}

    /**
     * @param  list<int>  $assetIds
     * @return int how many were added
     */
    public function handle(Contract $contract, array $assetIds): int
    {
        $customers = array_map(fn (array $asset) => $asset['customer_id'], $this->assetDetails->handle($assetIds));

        foreach ($assetIds as $assetId) {
            if (! array_key_exists($assetId, $customers)) {
                throw ValidationException::withMessages(['asset_ids' => __('contract.assets.not_found')]);
            }
            if ($customers[$assetId] !== $contract->customer_id) {
                throw ValidationException::withMessages(['asset_ids' => __('contract.assets.other_customer')]);
            }
        }

        return DB::transaction(function () use ($contract, $assetIds) {
            $existing = $contract->contractAssets()->pluck('asset_id')->all();
            $new = array_values(array_diff(array_unique($assetIds), $existing));

            foreach ($new as $assetId) {
                $contract->contractAssets()->create(['asset_id' => $assetId]);
            }

            if ($new !== []) {
                activity()->performedOn($contract)->event('assets_added')
                    ->withProperties(['asset_ids' => $new])->log('เพิ่มทรัพย์สินเข้าสัญญา');
            }

            return count($new);
        });
    }
}
