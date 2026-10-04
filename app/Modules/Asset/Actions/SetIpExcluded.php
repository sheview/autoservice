<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Marks an address as not to be given out (a printer range, a device outside the system), or
 * lets it be used again. Only a free address can be excluded.
 */
class SetIpExcluded
{
    public function __construct(private LogIpHistory $log) {}

    public function handle(IpAddress $ip, bool $excluded, User $user, ?string $notes = null): IpAddress
    {
        if ($excluded && $ip->status !== IpAddress::STATUS_AVAILABLE) {
            throw ValidationException::withMessages(['status' => __('asset.ipam.exclude_only_free')]);
        }
        if (! $excluded && $ip->status !== IpAddress::STATUS_EXCLUDED) {
            return $ip;
        }

        $ip->update(['status' => $excluded ? IpAddress::STATUS_EXCLUDED : IpAddress::STATUS_AVAILABLE, 'notes' => $notes ?? $ip->notes]);
        $this->log->handle($ip, $excluded ? 'excluded' : 'included', $user, $notes);

        return $ip;
    }
}
