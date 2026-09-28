<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The asset query of the list page and the Excel export: the user's branch scope + filters + sort.
 * Users without branch.all see only assets of their own branch and assets without a branch
 * (the same rule as TenantPolicy::inScope).
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
                ->orWhere('serial_number', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")
                ->orWhere('model', 'ilike', "%{$search}%")
                ->orWhere('location', 'ilike', "%{$search}%")))
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
     * Limit an asset query to what the user may see: the user's branch (and assets without a branch)
     * unless the user has branch.all; a customer account: the assets of its customer.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        // A customer account sees the assets of its customer only (see TenantPolicy).
        if ($user->customer_id !== null) {
            return $query->where('customer_id', $user->customer_id);
        }

        return $query->unless($user->can(PermissionCatalog::ALL_BRANCHES), fn (Builder $q) => $q->where(fn ($q) => $q
            ->whereNull('branch_id')
            ->when($user->branch_id, fn ($q, $branchId) => $q->orWhere('branch_id', $branchId))));
    }
}
