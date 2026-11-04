<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes several assets at once (ticked on the list, e.g. rows an import brought in twice),
 * each as DeleteAsset does. Only those the user may delete; ulids of another company are not seen
 * (TenantScope). Returns the codes deleted.
 */
class DeleteAssets
{
    public function __construct(private DeleteAsset $deleteAsset) {}

    /**
     * @param  list<string>  $ulids
     * @return list<string>
     */
    public function handle(array $ulids, User $user): array
    {
        return DB::transaction(fn () => Asset::query()->whereIn('ulid', $ulids)->get()
            ->filter(fn (Asset $asset) => $user->can('delete', $asset))
            ->each(fn (Asset $asset) => $this->deleteAsset->handle($asset))
            ->pluck('asset_code')->values()->all());
    }
}
