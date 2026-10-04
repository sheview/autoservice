<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpReservation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an address to an installed device: in use, linked to the asset, which then carries the
 * address (and MAC) itself. A reservation of the address ends as used. An address the asset
 * had before is let go first, so a device has one address here.
 */
class AssignIp
{
    public function __construct(private LogIpHistory $log, private ReleaseIp $releaseIp) {}

    /**
     * @param  array{asset_id: int, hostname?: string|null, mac_address?: string|null, responsible_id?: int|null,
     *     in_use_since?: string|null, notes?: string|null}  $data  validated
     */
    public function handle(IpAddress $ip, array $data, User $user): IpAddress
    {
        if ($ip->status === IpAddress::STATUS_EXCLUDED) {
            throw ValidationException::withMessages(['asset_id' => __('asset.ipam.excluded_cannot_use')]);
        }

        return DB::transaction(function () use ($ip, $data, $user) {
            $asset = Asset::findOrFail($data['asset_id']);

            // Its previous address (another row) goes back to free, with the history kept.
            IpAddress::query()
                ->where('asset_id', $asset->id)
                ->whereKeyNot($ip->id)
                ->get()
                ->each(fn (IpAddress $old) => $this->releaseIp->handle($old, $user, __('asset.ipam.moved_to', ['ip' => $ip->ip])));

            $mac = $data['mac_address'] ?? null ?: $asset->mac_address;
            $ip->update([
                'status' => IpAddress::STATUS_IN_USE,
                'asset_id' => $asset->id,
                'hostname' => $data['hostname'] ?? null,
                'mac_address' => $mac,
                'responsible_id' => $data['responsible_id'] ?? null,
                'in_use_since' => $data['in_use_since'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? $ip->notes,
            ]);
            $ip->reservations()->where('status', IpReservation::STATUS_ACTIVE)
                ->update(['status' => IpReservation::STATUS_USED, 'ended_at' => now()]);

            $asset->update(['ip_address' => $ip->ip, 'mac_address' => $mac]);
            $this->log->handle($ip, 'assigned', $user, $data['notes'] ?? null, $asset);

            return $ip;
        });
    }
}
