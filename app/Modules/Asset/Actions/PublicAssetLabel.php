<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Support\AssetKey;

/**
 * What anyone may know of an asset of the current company from its QR code, without signing in:
 * its code and a broad name (category and brand, e.g. "Notebook ASUS") — and only when the key of
 * its label matches. Never the serial, user, location, history, contract or price. Null for an
 * unknown code and for a wrong key alike.
 */
class PublicAssetLabel
{
    /**
     * @return array{id: int, asset_code: string, name: string}|null
     */
    public function handle(string $code, ?string $key): ?array
    {
        $asset = Asset::query()
            ->with('category:id,name')
            ->whereRaw('lower(asset_code) = ?', [mb_strtolower(trim($code))])
            ->first(['id', 'asset_code', 'brand', 'category_id', 'public_key']);

        if ($asset === null || ! AssetKey::matches($asset->public_key, $key)) {
            return null;
        }

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => trim(($asset->category?->name ?? '').' '.($asset->brand ?? '')),
        ];
    }
}
