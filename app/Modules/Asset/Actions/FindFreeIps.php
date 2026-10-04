<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Identity\Models\User;

/**
 * The first free addresses of a subnet, to suggest for a new device. The gateway is never offered.
 */
class FindFreeIps
{
    public function __construct(private IpTable $ipTable) {}

    /**
     * @return list<string>
     */
    public function handle(Subnet $subnet, User $user, int $count): array
    {
        return collect($this->ipTable->handle($subnet, $user))
            ->filter(fn (array $row) => $row['status'] === IpAddress::STATUS_AVAILABLE && $row['ip'] !== $subnet->gateway)
            ->take(max(1, $count))
            ->pluck('ip')
            ->values()
            ->all();
    }
}
