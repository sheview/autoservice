<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;

/**
 * The figures on top of the asset list, over the assets the user may see (as the list):
 * all of them, in use, away for repair, and warranties ending soon (SearchAssets::EXPIRING_DAYS).
 */
class AssetStats
{
    /**
     * @return array{total: int, in_use: int, in_repair: int, warranty_expiring: int}
     */
    public function handle(User $user): array
    {
        $visible = fn () => SearchAssets::visibleTo(Asset::query(), $user);
        $byStatus = $visible()->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');
        $today = now()->toDateString();

        return [
            'total' => (int) $byStatus->sum(),
            'in_use' => (int) ($byStatus[Asset::STATUS_IN_USE] ?? 0),
            'in_repair' => (int) ($byStatus[Asset::STATUS_IN_REPAIR] ?? 0),
            'warranty_expiring' => $visible()
                ->whereBetween('warranty_expires_at', [$today, now()->addDays(SearchAssets::EXPIRING_DAYS)->toDateString()])
                ->count(),
        ];
    }
}
