<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Subnet;

/**
 * The record id of a choice from IpChoices ("subnet_id:ip"), made when needed; null when the
 * choice does not name an address of a subnet of this company.
 */
class IpChoice
{
    public function __construct(private IpRecord $ipRecord) {}

    public function handle(string $key): ?int
    {
        [$subnetId, $ip] = array_pad(explode(':', $key, 2), 2, '');
        $subnet = ctype_digit($subnetId) ? Subnet::find((int) $subnetId) : null;

        return $subnet ? $this->ipRecord->handle($subnet, $ip)?->id : null;
    }
}
