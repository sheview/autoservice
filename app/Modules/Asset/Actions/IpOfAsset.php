<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\IpAddress;

/**
 * The address an asset has in IP management: the record linked to it, or else the subnet
 * address matching what the asset carries (by its owner's networks). For the asset page and
 * to suggest a ticket's IP.
 *
 * @return array{key: string, ulid: string|null, ip: string, cidr: string|null}|null
 */
class IpOfAsset
{
    public function handle(int $assetId): ?array
    {
        $record = IpAddress::query()->with('subnet')->where('asset_id', $assetId)->latest('id')->first();
        if ($record) {
            return ['key' => "{$record->subnet_id}:{$record->ip}", 'ulid' => $record->ulid, 'ip' => $record->ip, 'cidr' => $record->subnet?->cidr];
        }

        $asset = Asset::find($assetId, ['id', 'ip_address', 'customer_id']);
        if (! $asset?->ip_address) {
            return null;
        }
        $subnet = IpRecord::subnetsHolding($asset->ip_address)
            ->first(fn ($subnet) => $subnet->network?->customer_id === $asset->customer_id);

        return $subnet ? [
            'key' => "{$subnet->id}:{$asset->ip_address}",
            'ulid' => $subnet->addresses()->where('ip', $asset->ip_address)->value('ulid'),
            'ip' => $asset->ip_address,
            'cidr' => $subnet->cidr,
        ] : null;
    }
}
