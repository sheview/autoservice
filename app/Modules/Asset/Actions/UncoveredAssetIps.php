<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Addresses carried by assets (typed in or imported) that no subnet of the asset's owner covers,
 * so the free-IP page cannot show them. Grouped by owner and /24 range, each group a subnet the
 * page offers to create in one click. Only assets the user may see count.
 */
class UncoveredAssetIps
{
    /**
     * @return list<array{customer_id: int|null, cidr: string, count: int, samples: list<string>}> most addresses first
     */
    public function handle(User $user): array
    {
        $subnets = Subnet::query()->with('network:id,customer_id')->get(['id', 'network_id', 'first_int', 'prefix']);
        $groups = [];

        SearchAssets::visibleTo(Asset::query(), $user)
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->distinct()
            ->get(['customer_id', 'ip_address'])
            ->each(function (Asset $asset) use ($subnets, &$groups) {
                $n = filter_var($asset->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($asset->ip_address) : false;
                $customerId = $asset->customer_id ? (int) $asset->customer_id : null;
                if ($n === false || $this->covered($subnets, $customerId, $n)) {
                    return;
                }

                $cidr = long2ip($n & 0xFFFFFF00).'/24';
                $key = ($customerId ?? 'own').'|'.$cidr;
                $groups[$key] ??= ['customer_id' => $customerId, 'cidr' => $cidr, 'ips' => []];
                $groups[$key]['ips'][$n] = $asset->ip_address;
            });

        return collect($groups)
            ->map(function (array $group) {
                ksort($group['ips']);

                return [
                    'customer_id' => $group['customer_id'],
                    'cidr' => $group['cidr'],
                    'count' => count($group['ips']),
                    'samples' => array_slice(array_values($group['ips']), 0, 3),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** Whether a subnet of the same owner (the customer, or the company itself) holds the address. */
    private function covered(Collection $subnets, ?int $customerId, int $n): bool
    {
        return $subnets->contains(fn (Subnet $subnet) => $subnet->network
            && ($subnet->network->customer_id ? (int) $subnet->network->customer_id : null) === $customerId
            && $n >= $subnet->first_int
            && $n <= $subnet->first_int + 2 ** (32 - $subnet->prefix) - 1);
    }
}
