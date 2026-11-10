<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetSerial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a customer's device found on site (for another module: a PM work order) with only what
 * a technician types there — its kind, brand / model, serial number, where it is — and marks it to be
 * confirmed by the office (needs_review; ConfirmAssetReview). Its code is the next of its category;
 * with no name it is called by its brand and model, else its category. The same device twice is
 * refused, naming the asset that has it: a serial number, an equipment number or an IP already used.
 */
class AddAssetFromSite
{
    public function __construct(
        private SaveAsset $saveAsset,
        private FindDuplicateAssets $findDuplicates,
        private AddAssetPhoto $addPhoto,
    ) {}

    /**
     * @param  array{customer_id: int, category_id: int, name?: string|null, brand?: string|null, model?: string|null,
     *     serial_number?: string|null, property_no?: string|null, ip_address?: string|null, location?: string|null,
     *     floor?: string|null, room?: string|null, used_by?: string|null, parent_asset_id?: int|null}  $data  validated
     * @param  string  $note  where it came from, kept on the asset (added_note)
     */
    public function handle(array $data, string $note, ?UploadedFile $photo = null): Asset
    {
        $category = AssetCategory::query()->find($data['category_id']);
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => __('validation.exists', ['attribute' => __('asset.columns.category')])]);
        }
        $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $serial = $data['serial_number'] ?? null;

        if ($serial === null && $category->requires_serial) {
            throw ValidationException::withMessages(['serial_number' => __('asset.assets.serial_required', ['category' => $category->name])]);
        }
        if ($serial !== null) {
            $taken = AssetSerial::query()->with('asset:id,asset_code')->where('serial_number', $serial)->first(); // the collation ignores case
            if ($taken !== null) {
                throw ValidationException::withMessages(['serial_number' => __('asset.assets.serial_taken', ['serial' => $serial, 'code' => $taken->asset?->asset_code ?? '-'])]);
            }
        }
        foreach ($this->findDuplicates->handle($data, null, false) as $duplicate) {
            $reason = $duplicate['reasons'][0] ?? null;
            if ($reason !== null) {
                throw ValidationException::withMessages([$reason => __("asset.assets.duplicate_{$reason}", ['code' => $duplicate['asset_code'], 'name' => $duplicate['name']])]);
            }
        }

        return DB::transaction(function () use ($data, $category, $note, $photo) {
            $asset = $this->saveAsset->handle(null, [
                ...collect($data)->only(['customer_id', 'category_id', 'brand', 'model', 'serial_number', 'property_no', 'ip_address', 'location', 'floor', 'room', 'used_by', 'parent_asset_id'])->all(),
                'name' => $data['name'] ?? (trim(($data['brand'] ?? '').' '.($data['model'] ?? '')) ?: $category->name),
                'status' => Asset::STATUS_IN_USE,
                'quantity' => 1,
                'needs_review' => true,
                'added_note' => mb_substr($note, 0, 255),
            ]);
            if ($photo !== null) {
                $this->addPhoto->handle($asset->id, $photo);
            }

            return $asset;
        });
    }
}
