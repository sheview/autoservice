<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;

/**
 * Which addresses of a range are taken by assets (assets.ip_address) and which are free.
 *
 * Whether an address is free is decided by every asset of the company, whatever branch the
 * user works in: hiding another branch's device would show its address as free and invite a
 * clash. The device itself (name, code, link) is only shown when the user may see it.
 * Private ranges repeat between customers' networks, so the check can be limited to one
 * customer ($customerId) or to the company's own devices ($customerId = 0).
 */
class IpUsage
{
    /**
     * @param  list<string>  $addresses  from IpRange::parse()
     * @return list<array{ip: string, assets: list<array{ulid: string|null, asset_code: string|null, name: string|null,
     *     customer_id: int|null, visible: bool}>}> one row per address, in order; no assets = free
     */
    public function handle(User $user, array $addresses, ?int $customerId = null): array
    {
        $scoped = fn () => Asset::query()
            ->whereIn('ip_address', $addresses)
            ->when($customerId === 0, fn ($q) => $q->whereNull('customer_id'))
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id));

        $visibleIds = SearchAssets::visibleTo($scoped(), $user)->pluck('id')->flip();
        $byIp = $scoped()->orderBy('asset_code')->get(['id', 'ulid', 'asset_code', 'name', 'customer_id', 'ip_address'])->groupBy('ip_address');

        return array_map(fn (string $ip) => [
            'ip' => $ip,
            'assets' => ($byIp[$ip] ?? collect())->map(function (Asset $asset) use ($visibleIds) {
                $visible = $visibleIds->has($asset->id);

                return [
                    'ulid' => $visible ? $asset->ulid : null,
                    'asset_code' => $visible ? $asset->asset_code : null,
                    'name' => $visible ? $asset->name : null,
                    'customer_id' => $visible ? $asset->customer_id : null,
                    'visible' => $visible,
                ];
            })->values()->all(),
        ], $addresses);
    }
}
