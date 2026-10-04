<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Network;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Asset\Support\IpRange;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a subnet. Two subnets of the same owner (customer, or the company) may not
 * overlap: the same address would then have two states. Another customer may use the same range.
 * The range of a subnet with recorded addresses cannot change.
 */
class SaveSubnet
{
    /**
     * @param  array{network_id: int, cidr: string, gateway?: string|null, description?: string|null}  $data  validated
     */
    public function handle(?Subnet $subnet, array $data): Subnet
    {
        $parsed = IpRange::subnet($data['cidr']);
        if (is_string($parsed)) {
            throw ValidationException::withMessages(['cidr' => __("asset.ipam.cidr_{$parsed}")]);
        }

        $network = Network::findOrFail($data['network_id']);
        $size = 2 ** (32 - $parsed['prefix']);
        $last = $parsed['first'] + $size - 1;

        $overlaps = Subnet::query()
            ->when($subnet?->exists, fn ($q) => $q->whereKeyNot($subnet->id))
            ->whereHas('network', fn ($q) => $network->customer_id
                ? $q->where('customer_id', $network->customer_id)
                : $q->whereNull('customer_id'))
            // Overlap: each starts before the other ends.
            ->where('first_int', '<=', $last)
            ->whereRaw('first_int + power(2, 32 - prefix) - 1 >= ?', [$parsed['first']])
            ->value('cidr');
        if ($overlaps) {
            throw ValidationException::withMessages(['cidr' => __('asset.ipam.cidr_overlaps', ['cidr' => $overlaps])]);
        }

        if ($subnet?->exists && $subnet->cidr !== $parsed['cidr'] && $subnet->addresses()->exists()) {
            throw ValidationException::withMessages(['cidr' => __('asset.ipam.cidr_locked')]);
        }

        $gateway = filled($data['gateway'] ?? null) ? IpRange::toInt($data['gateway']) : null;
        if ($gateway !== null && ($gateway < $parsed['first'] || $gateway > $last)) {
            throw ValidationException::withMessages(['gateway' => __('asset.ipam.gateway_outside')]);
        }

        $subnet ??= new Subnet;
        $subnet->fill([
            'network_id' => $network->id,
            'cidr' => $parsed['cidr'],
            'first_int' => $parsed['first'],
            'prefix' => $parsed['prefix'],
            'gateway' => $data['gateway'] ?? null,
            'description' => $data['description'] ?? null,
        ])->save();

        return $subnet;
    }
}
