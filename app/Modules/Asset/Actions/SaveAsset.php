<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\SpecFields;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an asset of the current tenant.
 * An empty asset_code gets the next code of the category's prefix.
 */
class SaveAsset
{
    public function __construct(private GenerateAssetCode $generateCode) {}

    /**
     * @param  array{category_id: int, name: string, asset_code?: string|null, branch_id?: int|null,
     *     brand?: string|null, model?: string|null, serial_number?: string|null, status: string,
     *     location?: string|null, purchased_at?: string|null, purchase_price?: int|null,
     *     warranty_expires_at?: string|null, specs?: array<string, mixed>, notes?: string|null}  $data
     *     validated data; purchase_price in satang
     */
    public function handle(?Asset $asset, array $data): Asset
    {
        return DB::transaction(function () use ($asset, $data) {
            $asset ??= new Asset;
            $category = AssetCategory::findOrFail($data['category_id']);

            $data['specs'] = SpecFields::normalize($category->spec_fields, $data['specs'] ?? []);

            if (blank($data['asset_code'] ?? null)) {
                $data['asset_code'] = $asset->exists ? $asset->asset_code : $this->generateCode->handle($category->code_prefix);
            }

            $asset->fill($data)->save();

            return $asset;
        });
    }
}
