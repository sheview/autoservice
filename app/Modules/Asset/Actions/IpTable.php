<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Asset\Support\IpRange;
use App\Modules\Identity\Models\User;

/**
 * Every usable address of a subnet with its status, from what is recorded (ip_addresses) and
 * from the assets that carry the address (assets.ip_address) — so devices already registered
 * show as in use without typing them in twice.
 *
 * Only assets of the network's owner count (the customer, or the company's own devices for a
 * network without customer): private ranges repeat between customers.
 *
 * An address is a conflict when two assets carry it, when an asset carries an address that is
 * excluded, or when it is recorded for one asset but carried by another.
 * The asset itself (code, name, link) is only shown to users who may see it.
 */
class IpTable
{
    /**
     * @return list<array{ip: string, ip_int: int, status: string, conflict: string|null, ulid: string|null,
     *     hostname: string|null, mac_address: string|null, notes: string|null,
     *     assets: list<array{id: int, ulid: string|null, asset_code: string|null, name: string|null, serial_number: string|null, location: string|null, visible: bool}>}>
     */
    public function handle(Subnet $subnet, User $user): array
    {
        [$from, $to] = IpRange::usable($subnet->first_int, $subnet->prefix);
        $records = $subnet->addresses()->get()->keyBy('ip_int');

        $customerId = $subnet->network?->customer_id;
        $addresses = array_map(fn (int $n) => long2ip($n), range($from, $to));
        $scoped = fn () => Asset::query()
            ->whereIn('ip_address', $addresses)
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id), fn ($q) => $q->whereNull('customer_id'));
        $visible = SearchAssets::visibleTo($scoped(), $user)->pluck('id')->flip();
        $byIp = $scoped()->orderBy('asset_code')
            ->get(['id', 'ulid', 'asset_code', 'name', 'serial_number', 'location', 'ip_address', 'mac_address'])
            ->groupBy('ip_address');

        $rows = [];
        foreach ($addresses as $i => $ip) {
            $n = $from + $i;
            /** @var IpAddress|null $record */
            $record = $records->get($n);
            $carriers = $byIp->get($ip, collect());

            $status = $record?->status ?? IpAddress::STATUS_AVAILABLE;
            if ($status === IpAddress::STATUS_AVAILABLE && $carriers->isNotEmpty()) {
                $status = IpAddress::STATUS_IN_USE; // registered on an asset only
            }

            $conflict = match (true) {
                $carriers->count() > 1 => 'duplicate',
                $carriers->isNotEmpty() && $record?->status === IpAddress::STATUS_EXCLUDED => 'excluded',
                $carriers->isNotEmpty() && $record?->asset_id && ! $carriers->contains('id', $record->asset_id) => 'other_asset',
                default => null,
            };

            // The asset recorded on the address comes first, even when it no longer carries it.
            $assets = $carriers;
            if ($record?->asset_id && ! $carriers->contains('id', $record->asset_id) && $record->asset) {
                $assets = collect([$record->asset])->concat($carriers);
            }

            $rows[] = [
                'ip' => $ip,
                'ip_int' => $n,
                'status' => $conflict ? IpAddress::STATUS_CONFLICT : $status,
                'conflict' => $conflict,
                'ulid' => $record?->ulid,
                'hostname' => $record?->hostname,
                'mac_address' => $record?->mac_address ?? $carriers->first()?->mac_address,
                'notes' => $record?->notes,
                'assets' => $assets->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'ulid' => $visible->has($asset->id) ? $asset->ulid : null,
                    'asset_code' => $visible->has($asset->id) ? $asset->asset_code : null,
                    'name' => $visible->has($asset->id) ? $asset->name : null,
                    'serial_number' => $visible->has($asset->id) ? $asset->serial_number : null,
                    'location' => $visible->has($asset->id) ? $asset->location : null,
                    'visible' => $visible->has($asset->id),
                ])->values()->all(),
            ];
        }

        return $rows;
    }

    /**
     * How many addresses of the rows are in each status.
     *
     * @param  list<array{status: string}>  $rows
     * @return array{total: int, available: int, in_use: int, reserved: int, excluded: int, conflict: int}
     */
    public static function summary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return [
            'total' => count($rows),
            ...array_combine(IpAddress::STATUSES, array_map(fn (string $s) => $counts[$s] ?? 0, IpAddress::STATUSES)),
        ];
    }
}
