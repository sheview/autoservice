<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spare devices that can be asked for now (not asked for or out already), matching a search,
 * grouped by model so the person asking picks "a Lenovo E14" rather than hunting for serials.
 * Among the assets the user may ask for (SearchAssets::askableBy).
 */
class AvailableForCheckout
{
    public const LIMIT = 60;

    public function __construct(private CheckedOutQuantities $checkedOut) {}

    /**
     * @return list<array{key: string, name: string, brand: string|null, model: string|null, category: string|null,
     *     units: list<array{ulid: string, asset_code: string, serial_number: string|null, property_no: string|null, location: string|null, branch: string|null}>}>
     */
    public function handle(User $user, string $search): array
    {
        $search = trim($search);

        $assets = SearchAssets::askableBy(Asset::query(), $user)
            ->with(['category:id,name', 'branch:id,name'])
            ->where('status', Asset::STATUS_SPARE)
            // Some of its quantity is not asked for or out already.
            ->whereRaw('assets.quantity > ('.AssetCheckout::query()
                ->selectRaw('coalesce(sum(asset_checkouts.quantity), 0)')
                ->whereColumn('asset_checkouts.asset_id', 'assets.id')
                ->whereColumn('asset_checkouts.tenant_id', 'assets.tenant_id')
                ->whereIn('asset_checkouts.status', AssetCheckout::OPEN_STATUSES)
                ->toRawSql().')')
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

        $held = $this->checkedOut->handle($assets->modelKeys());

        return $assets
            ->groupBy(fn (Asset $asset) => mb_strtolower(trim($asset->category_id.'|'.$asset->brand.'|'.$asset->model.'|'.($asset->brand || $asset->model ? '' : $asset->name))))
            ->map(function ($units, string $key) use ($held) {
                $first = $units->first();

                return [
                    'key' => $key,
                    'name' => $first->name,
                    'brand' => $first->brand,
                    'model' => $first->model,
                    'category' => $first->category?->name,
                    'units' => $units->map(fn (Asset $asset) => [
                        ...$asset->only(['ulid', 'asset_code', 'serial_number', 'property_no', 'location', 'quantity', 'unit']),
                        'available' => max(0, $asset->quantity - ($held[$asset->id] ?? 0)),
                        'branch' => $asset->branch?->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
