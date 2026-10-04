<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpReservation;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Holds free addresses of a subnet for a job: they become reserved, with who, when and why.
 * All or nothing: an address that is no longer free stops the whole reservation.
 */
class ReserveIps
{
    public function __construct(private IpTable $ipTable, private IpRecord $ipRecord, private LogIpHistory $log) {}

    /**
     * @param  list<string>  $ips
     * @return list<IpAddress>
     */
    public function handle(Subnet $subnet, array $ips, User $user, string $purpose, ?string $notes = null): array
    {
        return DB::transaction(function () use ($subnet, $ips, $user, $purpose, $notes) {
            // One reservation at a time per subnet, so two people cannot take the same address.
            Subnet::whereKey($subnet->id)->lockForUpdate()->first();

            $free = collect($this->ipTable->handle($subnet, $user))
                ->where('status', IpAddress::STATUS_AVAILABLE)
                ->pluck('ip')
                ->flip();
            $taken = array_values(array_filter($ips, fn (string $ip) => ! $free->has($ip)));
            if ($taken !== []) {
                throw ValidationException::withMessages(['ips' => __('asset.ipam.not_free', ['ips' => implode(', ', $taken)])]);
            }

            return array_map(function (string $ip) use ($subnet, $user, $purpose, $notes) {
                $record = $this->ipRecord->handle($subnet, $ip);
                $record->update(['status' => IpAddress::STATUS_RESERVED, 'asset_id' => null, 'notes' => $notes ?? $record->notes]);
                IpReservation::create([
                    'ip_address_id' => $record->id,
                    'reserved_by' => $user->id,
                    'reserved_at' => now(),
                    'purpose' => $purpose,
                    'notes' => $notes,
                    'status' => IpReservation::STATUS_ACTIVE,
                ]);
                $this->log->handle($record, 'reserved', $user, $purpose);

                return $record;
            }, array_values(array_unique($ips)));
        });
    }
}
