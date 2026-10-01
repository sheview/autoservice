<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
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
     * @return array{search: string, branch_id: int|null, customer_id: int|null, category_id: int|null, status: string|null,
     *     warranty: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'branch_id' => $request->integer('branch_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
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
        return self::heldIds($user)->where('asset_id', $assetId)->exists();
    }

    /**
     * Ids of the assets the user holds now.
     *
     * @return Builder<AssetCheckout>
     */
    private static function heldIds(User $user): Builder
    {
        return AssetCheckout::query()
            ->select('asset_id')
            ->where('borrower_user_id', $user->id)
            ->whereIn('status', AssetCheckout::OPEN_STATUSES);
    }
}
