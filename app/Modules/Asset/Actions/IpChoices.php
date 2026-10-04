<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Support\IpRange;

/**
 * Addresses to pick for a ticket, for other modules: recorded ones matching the text (address or
 * hostname), and a typed full address that lies in a known subnet even without a record yet.
 * Each choice is "subnet_id:ip"; IpChoice turns the picked one into a record.
 */
class IpChoices
{
    /**
     * @param  int|null  $customerId  only networks of this customer (0 = the company's own); null = all
     * @return list<array{key: string, ip: string, cidr: string, status: string, hostname: string|null}>
     */
    public function handle(string $search, ?int $customerId = null, int $limit = 15): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $owner = fn ($q) => $q->when($customerId === 0, fn ($q) => $q->whereNull('customer_id'))
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id));

        $choices = IpAddress::query()
            ->with('subnet')
            ->whereHas('subnet.network', $owner)
            ->where(fn ($q) => $q->where('ip', 'like', "{$search}%")->orWhere('hostname', 'ilike', "%{$search}%"))
            ->orderBy('ip_int')
            ->limit($limit)
            ->get()
            ->map(fn (IpAddress $ip) => [
                'key' => "{$ip->subnet_id}:{$ip->ip}",
                'ip' => $ip->ip,
                'cidr' => $ip->subnet?->cidr,
                'status' => $ip->status,
                'hostname' => $ip->hostname,
            ]);

        if (IpRange::toInt($search) !== null) {
            foreach (IpRecord::subnetsHolding($search) as $subnet) {
                $key = "{$subnet->id}:{$search}";
                $owned = $customerId === null || ($customerId === 0 ? $subnet->network?->customer_id === null : $subnet->network?->customer_id === $customerId);
                if ($owned && ! $choices->contains('key', $key)) {
                    $choices->push(['key' => $key, 'ip' => $search, 'cidr' => $subnet->cidr, 'status' => IpAddress::STATUS_AVAILABLE, 'hostname' => null]);
                }
            }
        }

        return $choices->values()->all();
    }
}
