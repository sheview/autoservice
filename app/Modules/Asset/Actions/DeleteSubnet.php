<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\Subnet;
use Illuminate\Validation\ValidationException;

/**
 * Removes (soft deletes) a subnet none of whose addresses is reserved, in use or excluded.
 */
class DeleteSubnet
{
    public function handle(Subnet $subnet): void
    {
        if ($subnet->addresses()->where('status', '!=', IpAddress::STATUS_AVAILABLE)->exists()) {
            throw ValidationException::withMessages(['subnet' => __('asset.ipam.subnet_in_use')]);
        }

        $subnet->delete();
    }
}
