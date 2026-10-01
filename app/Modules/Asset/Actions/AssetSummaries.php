<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Short asset rows for other modules (e.g. the assets of a contract, the asset picker),
 * limited to what the user may see (same branch rule as SearchAssets).
 */
class AssetSummaries
{
    /**
     * @param  array{ids?: list<int>, exclude?: list<int>, customer_id?: int|null, search?: string, limit?: int}  $criteria
     * @return list<array{id: int, ulid: string, asset_code: string, name: string, category: string|null,
     *     branch: string|null, branch_province: string|null, brand: string|null, model: string|null, status: string,
     *     serial_number: string|null, property_no: string|null, location: string|null, warranty_expires_at: string|null,
     *     customer_id: int|null}>
     */
    public function handle(User $user, array $criteria): array
    {
        $search = trim($criteria['search'] ?? '');

        return SearchAssets::visibleTo(Asset::query(), $user)
            ->with(['category:id,name', 'branch:id,name,province'])
            ->when(array_key_exists('ids', $criteria), fn (Builder $q) => $q->whereKey($criteria['ids']))
            ->when($criteria['exclude'] ?? [], fn (Builder $q, $ids) => $q->whereKeyNot($ids))
            ->when(array_key_exists('customer_id', $criteria), fn (Builder $q) => $q->where('customer_id', $criteria['customer_id']))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('asset_code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('serial_number', 'ilike', "%{$search}%")
                ->orWhere('property_no', 'ilike', "%{$search}%")))
            ->orderBy('asset_code')
            ->when($criteria['limit'] ?? null, fn (Builder $q, $limit) => $q->limit($limit))
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'ulid' => $asset->ulid,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
                'branch_province' => $asset->branch?->province,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'status' => $asset->status,
                'serial_number' => $asset->serial_number,
                'property_no' => $asset->property_no,
                'location' => $asset->location,
                'ip_address' => $asset->ip_address,
                'used_by' => $asset->used_by,
                'department' => $asset->department,
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                'customer_id' => $asset->customer_id,
            ])
            ->all();
    }
}
