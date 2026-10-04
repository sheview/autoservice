<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Asset\Support\IpRange;
use Illuminate\Support\Collection;

/**
 * The row of an address of a subnet, made when first needed (opening its page, reserving it,
 * linking a ticket). An address carried by one asset of the network's owner starts as in use
 * by that asset, so nothing is typed twice.
 */
class IpRecord
{
    public function handle(Subnet $subnet, string $ip): ?IpAddress
    {
        $n = IpRange::toInt($ip);
        [$from, $to] = IpRange::usable($subnet->first_int, $subnet->prefix);
        if ($n === null || $n < $from || $n > $to) {
            return null;
        }

        $existing = $subnet->addresses()->where('ip_int', $n)->first();
        if ($existing) {
            return $existing;
        }

        $customerId = $subnet->network?->customer_id;
        $carriers = Asset::query()
            ->where('ip_address', $ip)
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id), fn ($q) => $q->whereNull('customer_id'))
            ->limit(2)
            ->get(['id', 'mac_address']);
        $only = $carriers->count() === 1 ? $carriers->first() : null;

        return $subnet->addresses()->create([
            'ip' => $ip,
            'ip_int' => $n,
            'status' => $only ? IpAddress::STATUS_IN_USE : IpAddress::STATUS_AVAILABLE,
            'asset_id' => $only?->id,
            'mac_address' => $only?->mac_address,
        ]);
    }

    /**
     * The subnets (of the network's owner given) that hold an address.
     *
     * @return Collection<int, Subnet>
     */
    public static function subnetsHolding(string $ip): Collection
    {
        $n = IpRange::toInt($ip);
        if ($n === null) {
            return collect();
        }

        return Subnet::query()
            ->with('network')
            ->where('first_int', '<=', $n)
            ->whereRaw('first_int + power(2, 32 - prefix) - 1 >= ?', [$n])
            ->get();
    }
}
