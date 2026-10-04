<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Network;

/**
 * Creates or updates a network (of a customer at a site, or of the company itself).
 */
class SaveNetwork
{
    /**
     * @param  array{customer_id: int|null, site_id: int|null, name: string, vlan_id?: int|null, description?: string|null}  $data  validated
     */
    public function handle(?Network $network, array $data): Network
    {
        $network ??= new Network;
        $network->fill($data)->save();

        return $network;
    }
}
