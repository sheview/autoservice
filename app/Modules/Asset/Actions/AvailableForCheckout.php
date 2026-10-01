<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spare devices that can be asked for now (not asked for or out already), matching a search,
 * grouped by model so the person asking picks "a Lenovo E14" rather than hunting for serials.
 * Within what the user may see (SearchAssets).
 */
class AvailableForCheckout
{
    public const LIMIT = 60;

    /**
     * @return list<array{key: string, name: string, brand: string|null, model: string|null, category: string|null,
     *     units: list<array{ulid: string, asset_code: string, serial_number: string|null, property_no: string|null, location: string|null, branch: string|null}>}>
     */
    public function handle(User $user, string $search): array
    {
        $search = trim($search);

        $assets = SearchAssets::visibleTo(Asset::query(), $user)
            ->with(['category:id,name', 'branch:id,name'])
            ->where('status', Asset::STATUS_SPARE)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('asset_checkouts')
                ->whereColumn('asset_checkouts.asset_id', 'assets.id')
                ->whereIn('asset_checkouts.status', AssetCheckout::OPEN_STATUSES)
                ->whereNull('asset_checkouts.deleted_at'))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")
                ->orWhere('model', 'ilike', "%{$search}%")
                ->orWhere('asset_code', 'ilike', "%{$search}%")
                ->orWhere('serial_number', 'ilike', "%{$search}%")
                ->orWhere('property_no', 'ilike', "%{$search}%")
                ->orWhereHas('category', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))))
            ->orderBy('name')->orderBy('asset_code')
            ->limit(self::LIMIT)
            ->get();

        return $assets
            ->groupBy(fn (Asset $asset) => mb_strtolower(trim($asset->category_id.'|'.$asset->brand.'|'.$asset->model.'|'.($asset->brand || $asset->model ? '' : $asset->name))))
            ->map(function ($units, string $key) {
                $first = $units->first();

                return [
                    'key' => $key,
                    'name' => $first->name,
                    'brand' => $first->brand,
                    'model' => $first->model,
                    'category' => $first->category?->name,
                    'units' => $units->map(fn (Asset $asset) => [
                        ...$asset->only(['ulid', 'asset_code', 'serial_number', 'property_no', 'location']),
                        'branch' => $asset->branch?->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
