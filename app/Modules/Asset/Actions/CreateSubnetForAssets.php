<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Subnet;
use Illuminate\Support\Facades\DB;

/**
 * One click from a suggestion of UncoveredAssetIps: a network named after the range, of the
 * customer (or the company itself), with the subnet in it. Both can be renamed, moved to a site
 * or another network afterwards. Nothing is kept when the subnet is refused (e.g. it overlaps).
 */
class CreateSubnetForAssets
{
    public function __construct(private SaveNetwork $saveNetwork, private SaveSubnet $saveSubnet) {}

    public function handle(?int $customerId, string $cidr): Subnet
    {
        return DB::transaction(function () use ($customerId, $cidr) {
            $network = $this->saveNetwork->handle(null, [
                'customer_id' => $customerId,
                'site_id' => null,
                'name' => __('asset.ipam.suggested_network_name', ['cidr' => $cidr]),
            ]);

            return $this->saveSubnet->handle(null, ['network_id' => $network->id, 'cidr' => $cidr]);
        });
    }
}
