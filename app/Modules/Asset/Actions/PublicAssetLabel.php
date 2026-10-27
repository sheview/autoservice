<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Support\AssetKey;
use App\Modules\Document\Support\PhotoSlots;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What anyone may know of an asset of the current company from its QR code, without signing in,
 * and only when the key of its label matches: what the device is (code, name, category, brand,
 * model, serial numbers, status), its warranty and its main photo. Never where it is, who uses
 * it, its network address, history, contract or price. Null for an unknown code and for a wrong
 * key alike.
 */
class PublicAssetLabel
{
    /**
     * @return array{id: int, asset_code: string, name: string, category: string|null, brand: string|null, model: string|null,
     *     serials: list<string>, status: string, warranty_expires_at: string|null, warranty: string}|null
     */
    public function handle(string $code, ?string $key): ?array
    {
        $asset = $this->find($code, $key);
        if ($asset === null) {
            return null;
        }

        $expires = $asset->warranty_expires_at;
        $today = now()->startOfDay();

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category' => $asset->category?->name,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serials' => $asset->serials->pluck('serial_number')->all(),
            'status' => $asset->status,
            'warranty_expires_at' => $expires?->toDateString(),
            'warranty' => match (true) {
                $expires === null => 'none',
                $expires->lt($today) => 'expired',
                $expires->lte($today->copy()->addDays(SearchAssets::EXPIRING_DAYS)) => 'expiring',
                default => 'active',
            },
        ];
    }

    /** The main photo of the asset (slot 0), under the same key check; null when there is none. */
    public function photo(string $code, ?string $key): ?Media
    {
        $asset = $this->find($code, $key);

        return $asset === null ? null : PhotoSlots::find($asset, 0);
    }

    private function find(string $code, ?string $key): ?Asset
    {
        $asset = Asset::query()
            ->with(['category:id,name', 'serials'])
            ->whereRaw('lower(asset_code) = ?', [mb_strtolower(trim($code))])
            ->first();

        return $asset !== null && AssetKey::matches($asset->public_key, $key) ? $asset : null;
    }
}
