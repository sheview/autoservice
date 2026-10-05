<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;

/**
 * One device as staff see it on its QR page, found by its asset code within what the user may see:
 * what it is, where, who uses it, its warranty, and how many are free — of this record and of the
 * whole model. Free counts come from the same place as the asset list (AssetHeldQuantities):
 * "this one" counts the record (a device on loan reads 0 of 1), "the model" adds up every device
 * of the same category, brand and model (SameModelAssets) — two different numbers, both shown.
 */
class AssetCard
{
    public function __construct(
        private AssetHeldQuantities $held,
        private SameModelAssets $sameModel,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, string $code): ?array
    {
        $asset = SearchAssets::visibleTo(Asset::query(), $user)
            ->with(['category:id,name', 'branch:id,name', 'serials'])
            ->whereRaw('lower(asset_code) = ?', [mb_strtolower(trim($code))])
            ->first();
        if ($asset === null) {
            return null;
        }

        $today = now()->startOfDay();
        $expires = $asset->warranty_expires_at;
        $warranty = match (true) {
            $expires === null => 'none',
            $expires->lt($today) => 'expired',
            $expires->lte($today->copy()->addDays(SearchAssets::EXPIRING_DAYS)) => 'expiring',
            default => 'active',
        };

        $units = collect($this->sameModel->handle($user, $asset));
        $unitAssets = $units->isEmpty() ? collect([$asset]) : Asset::query()->whereIn('ulid', $units->pluck('ulid'))->get(['id', 'quantity']);
        $held = $this->held->handle($unitAssets->pluck('id')->push($asset->id)->unique()->values()->all());
        $free = fn (Asset $a) => max(0, (int) $a->quantity - ($held[$a->id] ?? 0));

        return [
            ...$asset->only(['id', 'ulid', 'asset_code', 'name', 'brand', 'model', 'status', 'used_by', 'department', 'location', 'customer_id', 'branch_id', 'unit']),
            'category' => $asset->category?->name,
            'branch' => $asset->branch?->name,
            'serials' => $asset->serials->pluck('serial_number')->all(),
            'warranty_expires_at' => $expires?->toDateString(),
            'warranty' => $warranty,
            'quantity' => (int) $asset->quantity,
            'available' => $free($asset),
            'model_units' => $unitAssets->count(),
            'model_available' => $unitAssets->sum(fn (Asset $a) => $free($a) > 0 ? 1 : 0),
        ];
    }
}
