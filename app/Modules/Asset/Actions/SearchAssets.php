<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The asset query of the list page and the Excel export: the user's scope of assets.view
 * (visibleTo, the same rule as AssetPolicy) + filters + sort.
 */
class SearchAssets
{
    public const SORTABLE = ['asset_code', 'name', 'status', 'purchased_at', 'warranty_expires_at', 'created_at'];

    public const WARRANTY_FILTERS = ['active', 'expiring', 'expired', 'none'];

    /** "expiring" = warranty ends within this many days. */
    public const EXPIRING_DAYS = 90;

    /**
     * @return array{search: string, branch_id: int|null, customer_id: int|null, category_id: int|null, asset_type: string|null, status: string|null,
     *     warranty: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'branch_id' => $request->integer('branch_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            // Hardware or software: the type of the asset's category.
            'asset_type' => in_array($request->input('asset_type'), AssetCategory::ASSET_TYPES, true) ? $request->input('asset_type') : null,
            'status' => in_array($request->input('status'), Asset::STATUSES, true) ? $request->input('status') : null,
            'warranty' => in_array($request->input('warranty'), self::WARRANTY_FILTERS, true) ? $request->input('warranty') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'asset_code',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Asset>
     */
    public function handle(User $user, array $filters): Builder
    {
        $today = now()->toDateString();
        $search = $filters['search'] ?? '';

        return self::visibleTo(Asset::query(), $user)
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('asset_code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhereHas('serials', fn (Builder $q) => $q->where('serial_number', 'ilike', "%{$search}%"))
                ->orWhere('property_no', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")
                ->orWhere('model', 'ilike', "%{$search}%")
                ->orWhere('location', 'ilike', "%{$search}%")
                ->orWhere('ip_address', 'ilike', "%{$search}%")
                ->orWhere('mac_address', 'ilike', "%{$search}%")
                ->orWhere('used_by', 'ilike', "%{$search}%")))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['asset_type'] ?? null, fn (Builder $q, $type) => $q->whereHas('category', fn (Builder $q) => $q->where('asset_type', $type)))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when(($filters['warranty'] ?? null) === 'active', fn (Builder $q) => $q->where('warranty_expires_at', '>=', $today))
            ->when(($filters['warranty'] ?? null) === 'expiring', fn (Builder $q) => $q
                ->whereBetween('warranty_expires_at', [$today, now()->addDays(self::EXPIRING_DAYS)->toDateString()]))
            ->when(($filters['warranty'] ?? null) === 'expired', fn (Builder $q) => $q->where('warranty_expires_at', '<', $today))
            ->when(($filters['warranty'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('warranty_expires_at'))
            ->orderBy($filters['sort'] ?? 'asset_code', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }

    /**
     * Limit an asset query to what the user may see with $permission (DataScope): every asset
     * (all), those of the user's branch and of no branch (branch), those of the account's
     * customer (customer), or those the user holds now (own).
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function visibleTo(Builder $query, User $user, string $permission = 'assets.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission,
            branch: $query->qualifyColumn('branch_id'),
            customer: $query->qualifyColumn('customer_id'),
            own: fn (Builder $q) => $q->whereIn($q->qualifyColumn('id'), self::heldIds($user)));
    }

    /**
     * Spare assets the user may ask for on an issue/loan form: what they may see, except that
     * someone who only sees what they hold (scope own) may still ask for the assets of their
     * branch (and those of no branch).
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function askableBy(Builder $query, User $user): Builder
    {
        if (DataScope::of($user, 'assets.view') !== PermissionCatalog::SCOPE_OWN) {
            return self::visibleTo($query, $user);
        }

        $branch = $query->qualifyColumn('branch_id');

        return $query->where(fn ($q) => $q->whereNull($branch)->when($user->branch_id, fn ($q, $id) => $q->orWhere($branch, $id)));
    }

    /** Whether the user holds the asset now (asked for or handed over to them, not back yet). */
    public static function holds(User $user, int $assetId): bool
    {
        return self::heldIds($user)->where('checkout_items.asset_id', $assetId)->exists();
    }

    /**
     * Ids of the assets the user holds now: lines of their requests (as the borrower) that are
     * asked for, approved, or handed out and not back.
     *
     * @return Builder<CheckoutItem>
     */
    private static function heldIds(User $user): Builder
    {
        return CheckoutItem::query()
            ->select('checkout_items.asset_id')
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->where('checkout_requests.borrower_user_id', $user->id)
            ->whereIn('checkout_requests.status', [...CheckoutRequest::OPEN_STATUSES, CheckoutRequest::STATUS_FULFILLED])
            ->where('checkout_items.item_type', CheckoutItem::TYPE_ASSET)
            ->whereRaw(AssetHeldQuantities::HELD_SQL.' > 0');
    }
}
