<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpAssetHistory;
use App\Modules\Identity\Models\User;

/**
 * Writes one line of an address's history, with the device as it is now.
 */
class LogIpHistory
{
    public function handle(IpAddress $ip, string $action, ?User $user, ?string $notes = null, ?Asset $asset = null): void
    {
        $asset ??= $ip->asset_id ? Asset::withTrashed()->find($ip->asset_id) : null;

        IpAssetHistory::create([
            'ip_address_id' => $ip->id,
            'action' => $action,
            'asset_id' => $asset?->id,
            'asset_code' => $asset?->asset_code,
            'hostname' => $ip->hostname,
            'mac_address' => $ip->mac_address,
            'user_id' => $user?->id,
            'notes' => $notes,
        ]);
    }
}
