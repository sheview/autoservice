<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Identity\Models\User;

/**
 * Changes the details of an address (hostname, MAC, who looks after it, since when, notes).
 */
class UpdateIpDetails
{
    public function __construct(private LogIpHistory $log) {}

    /**
     * @param  array{hostname?: string|null, mac_address?: string|null, responsible_id?: int|null, in_use_since?: string|null, notes?: string|null}  $data  validated
     */
    public function handle(IpAddress $ip, array $data, User $user): IpAddress
    {
        $ip->fill($data);
        if ($ip->isDirty()) {
            $ip->save();
            $this->log->handle($ip, 'updated', $user);
        }

        return $ip;
    }
}
