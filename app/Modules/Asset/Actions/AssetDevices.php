<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * What a repair job needs to know about assets (Service): the device itself and its warranty date.
 * Not limited by the user's branch: callers check access themselves.
 */
class AssetDevices
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: string, brand: string|null, model: string|null, serial_number: string|null,
     *     property_no: string|null, location: string|null, warranty_expires_at: string|null}> keyed by asset id
     */
    public function handle(array $ids): array
    {
        return Asset::query()
            ->whereKey($ids)
            ->get(['id', 'name', 'brand', 'model', 'serial_number', 'property_no', 'location', 'ip_address', 'used_by', 'department', 'warranty_expires_at'])
            ->mapWithKeys(fn (Asset $asset) => [$asset->id => [
                ...$asset->only(['name', 'brand', 'model', 'serial_number', 'property_no', 'location', 'ip_address', 'used_by', 'department']),
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
            ]])
            ->all();
    }
}
