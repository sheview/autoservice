<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Key facts of assets by id or ulid, for other modules (Contract, Service). Not limited by the
 * user's branch: callers check access themselves. Unknown ids (other tenant, deleted) are left out.
 */
class AssetDetails
{
    /**
     * @param  list<int|string>  $keys  ids, or ulids when $byUlid
     * @return array<int, array{id: int, ulid: string, asset_code: string, name: string, customer_id: int|null, branch_id: int|null}>
     *                                                                                                                                keyed by asset id
     */
    public function handle(array $keys, bool $byUlid = false): array
    {
        return Asset::query()
            ->whereIn($byUlid ? 'ulid' : 'id', $keys)
            ->get(['id', 'ulid', 'asset_code', 'name', 'customer_id', 'branch_id'])
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => $asset->only(['id', 'ulid', 'asset_code', 'name', 'customer_id', 'branch_id'])])
            ->all();
    }
}
