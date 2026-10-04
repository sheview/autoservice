<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Network;
use Illuminate\Validation\ValidationException;

/**
 * Removes (soft deletes) a network that has no subnets left.
 */
class DeleteNetwork
{
    public function handle(Network $network): void
    {
        if ($network->subnets()->exists()) {
            throw ValidationException::withMessages(['network' => __('asset.ipam.network_has_subnets')]);
        }

        $network->delete();
    }
}
