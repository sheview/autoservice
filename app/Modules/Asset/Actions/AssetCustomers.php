<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * The customer of each asset, for the Contract module. Unknown ids (other tenant, deleted)
 * are not in the result.
 */
class AssetCustomers
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, int|null> asset id => customer id
     */
    public function handle(array $assetIds): array
    {
        return Asset::whereKey($assetIds)->pluck('customer_id', 'id')->all();
    }
}
