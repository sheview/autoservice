<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Identity\Models\User;

/**
 * The devices of the same model as an asset: same category, brand and model (ignoring case and
 * spaces around), within what the user may see (SearchAssets), the asset itself included.
 * Empty when the asset has neither brand nor model, as nothing then says they are the same.
 */
class SameModelAssets
{
    public const LIMIT = 200;

    /**
     * @return list<array{ulid: string, asset_code: string, serial_number: string|null, property_no: string|null,
     *     status: string, location: string|null, branch: string|null, holder: string|null}>
     */
    public function handle(User $user, Asset $asset): array
    {
        if (blank($asset->brand) && blank($asset->model)) {
            return [];
        }

        $same = fn (string $column) => fn ($q) => blank($asset->{$column})
            ? $q->where(fn ($q) => $q->whereNull($column)->orWhereRaw("trim({$column}) = ''"))
            : $q->whereRaw("lower(trim({$column})) = ?", [mb_strtolower(trim($asset->{$column}))]);

        $units = SearchAssets::visibleTo(Asset::query(), $user)
            ->with('branch:id,name')
            ->where('category_id', $asset->category_id)
            ->where($same('brand'))
            ->where($same('model'))
            ->orderBy('asset_code')
            ->limit(self::LIMIT)
            ->get();

        // Who has each device now: handed out on a request line and not back yet (the latest).
        $holders = CheckoutItem::query()
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->where('checkout_items.item_type', CheckoutItem::TYPE_ASSET)
            ->whereIn('checkout_items.asset_id', $units->modelKeys())
            ->whereColumn('checkout_items.qty_fulfilled', '>', 'checkout_items.qty_returned')
            ->orderBy('checkout_items.id')
            ->pluck('checkout_requests.borrower_name', 'checkout_items.asset_id');

        return $units->map(fn (Asset $unit) => [
            ...$unit->only(['ulid', 'asset_code', 'serial_number', 'property_no', 'status', 'location']),
            'branch' => $unit->branch?->name,
            'holder' => $holders[$unit->id] ?? null,
        ])->all();
    }
}
