<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;

/**
 * Addresses by id as plain arrays, for other modules (the IP a ticket is about).
 */
class IpLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, ulid: string, ip: string, status: string, cidr: string|null}>
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : IpAddress::query()
            ->whereKey($ids)
            ->with('subnet')
            ->get()
            ->mapWithKeys(fn (IpAddress $ip) => [$ip->id => [
                ...$ip->only(['id', 'ulid', 'ip', 'status']),
                'cidr' => $ip->subnet?->cidr,
            ]])
            ->all();
    }
}
