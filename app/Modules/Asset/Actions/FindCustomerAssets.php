<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A customer's equipment found by what is read off it on site — its code (or QR), serial number,
 * equipment number, IP, name or model — or by id, for another module that has already checked the user
 * works for that customer (a technician on the customer's PM work order). Someone who may see the
 * register sees only what it lets them (SearchAssets::visibleTo: their branch, …); someone who may not
 * (a technician only) sees the customer's equipment. An exact match comes first. Short rows only.
 */
class FindCustomerAssets
{
    public const LIMIT = 20;

    /**
     * @param  list<int>  $exclude  asset ids left out (already on the work order)
     * @param  list<int>|null  $ids  only these assets (no search, no limit)
     * @return list<array{id: int, ulid: string, asset_code: string, name: string, category: string|null, brand: string|null,
     *     model: string|null, serial_number: string|null, property_no: string|null, location: string|null, needs_review: bool}>
     */
    public function handle(int $customerId, User $user, string $search = '', array $exclude = [], ?array $ids = null): array
    {
        $search = trim($search);
        if ($search === '' && $ids === null) {
            return [];
        }
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query = Asset::query()
            ->with('category:id,name')
            ->where('customer_id', $customerId)
            ->when($exclude, fn (Builder $q) => $q->whereKeyNot($exclude))
            ->when($ids !== null, fn (Builder $q) => $q->whereKey($ids))
            ->when($search !== '', fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->where('asset_code', 'like', $like)
                    ->orWhere('ulid', $search)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('serials', fn (Builder $q) => $q->where('serial_number', 'like', $like))
                    ->orWhere('property_no', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('model', 'like', $like))
                // what was scanned exactly first, so the limit never hides it
                ->orderByRaw('case when asset_code = ? or ulid = ? or property_no = ? or ip_address = ? or exists (select 1 from asset_serials s where s.asset_id = assets.id and s.serial_number = ? and s.tenant_id = assets.tenant_id and s.deleted_at is null) then 0 else 1 end', array_fill(0, 5, $search))
                ->limit(self::LIMIT));
        if ($user->can('assets.view')) {
            SearchAssets::visibleTo($query, $user);
        }

        return $query->orderBy('asset_code')
            ->get()
            ->map(fn (Asset $asset) => [
                ...$asset->only(['id', 'ulid', 'asset_code', 'name', 'brand', 'model', 'serial_number', 'property_no', 'location', 'needs_review']),
                'category' => $asset->category?->name,
            ])
            ->all();
    }
}
