<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpReservation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes an address off its device (or ends its reservation) and makes it free again. The
 * asset stops carrying it; the history keeps which device had it.
 */
class ReleaseIp
{
    public function __construct(private LogIpHistory $log) {}

    public function handle(IpAddress $ip, User $user, ?string $notes = null): IpAddress
    {
        return DB::transaction(function () use ($ip, $user, $notes) {
            $asset = $ip->asset_id ? Asset::find($ip->asset_id) : null;
            $this->log->handle($ip, 'released', $user, $notes, $asset);

            if ($asset && $asset->ip_address === $ip->ip) {
                $asset->update(['ip_address' => null]);
            }
            $ip->reservations()->where('status', IpReservation::STATUS_ACTIVE)
                ->update(['status' => IpReservation::STATUS_RELEASED, 'ended_at' => now()]);
            $ip->update([
                'status' => IpAddress::STATUS_AVAILABLE,
                'asset_id' => null,
                'hostname' => null,
                'mac_address' => null,
                'responsible_id' => null,
                'in_use_since' => null,
            ]);

            return $ip;
        });
    }
}
