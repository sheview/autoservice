<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetSerial;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers what a purchase brought (Inventory module, a purchase receipt) as spare assets. In a
 * category counted by serial each device becomes an asset of its own (its code, label, holder and
 * history), so one serial is needed for every unit; otherwise one asset holds the quantity (with
 * any serials written down). A serial may be on one asset only. All or nothing.
 */
class RegisterPurchasedAsset
{
    public function __construct(private CreateAsset $createAsset) {}

    /**
     * @param  array{category_id: int, name: string, brand?: string|null, model?: string|null, quantity: int, unit?: string|null,
     *     location?: string|null, purchased_at?: string|null, purchase_price?: int|null, notes?: string|null}  $data  price in satang
     * @param  list<string>  $serials
     * @return list<array{id: int, ulid: string, asset_code: string, quantity: int}> the assets made
     */
    public function handle(array $data, array $serials, User $actor): array
    {
        $category = AssetCategory::query()->find($data['category_id']);
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => __('validation.exists', ['attribute' => __('asset.columns.category')])]);
        }
        if ($category->requires_serial && count($serials) !== $data['quantity']) {
            throw ValidationException::withMessages(['category_id' => __('asset.assets.purchase_serials_needed', [
                'category' => $category->name, 'count' => $data['quantity'],
            ])]);
        }

        $taken = AssetSerial::query()->with('asset:id,asset_code')
            ->whereIn(DB::raw('lower(serial_number)'), array_map(mb_strtolower(...), $serials))->first();
        if ($taken !== null) {
            throw ValidationException::withMessages(['serials' => __('asset.assets.serial_taken', [
                'serial' => $taken->serial_number, 'code' => $taken->asset?->asset_code ?? '-',
            ])]);
        }

        // One device per serial, or one asset holding the lot.
        $units = $category->requires_serial
            ? array_map(fn (string $serial) => [[...$data, 'quantity' => 1], [$serial]], $serials)
            : [[$data, $serials]];

        return DB::transaction(fn () => array_map(function (array $unit) use ($actor) {
            [$fields, $unitSerials] = $unit;
            $asset = $this->createAsset->handle([...$fields, 'status' => Asset::STATUS_SPARE], $unitSerials, [], null, $actor);

            return [...$asset->only(['id', 'ulid', 'asset_code']), 'quantity' => (int) $asset->quantity];
        }, $units));
    }
}
