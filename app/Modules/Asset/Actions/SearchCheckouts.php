<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The issue/loan list: the forms the user reaches with asset-checkouts.view (visibleTo), with
 * search, filters and sort.
 */
class SearchCheckouts
{
    public const SORTABLE = ['created_at', 'checkout_no', 'due_on'];

    /** "open" = waiting or out; "overdue" = loans past their due date; or one status. */
    public const STATUS_FILTERS = ['open', 'overdue', 'all', ...AssetCheckout::STATUSES];

    /**
     * @return array{search: string, status: string, type: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'type' => in_array($request->input('type'), AssetCheckout::TYPES, true) ? $request->input('type') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<AssetCheckout>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';

        return AssetCheckout::query()
            ->with('asset:id,ulid,asset_code,name,serial_number,branch_id')
            ->has('asset')
            ->tap(fn (Builder $q) => self::visibleTo($q, $user))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('checkout_no', 'ilike', "%{$search}%")
                ->orWhere('borrower_name', 'ilike', "%{$search}%")
                ->orWhere('borrower_department', 'ilike', "%{$search}%")
                ->orWhereHas('asset', fn ($q) => $q
                    ->where('asset_code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('serial_number', 'ilike', "%{$search}%"))))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', AssetCheckout::OPEN_STATUSES))
            ->when($status === 'overdue', fn (Builder $q) => $q->where('status', AssetCheckout::STATUS_APPROVED)
                ->whereNotNull('due_on')->where('due_on', '<', today()->toDateString()))
            ->when(in_array($status, AssetCheckout::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            // Set by the summaries (Reporting module): one person (a user, or a name from outside) or one project.
            ->when($filters['borrower_user_id'] ?? null, fn (Builder $q, $id) => $q->where('borrower_user_id', $id))
            ->when($filters['borrower_name'] ?? null, fn (Builder $q, $name) => $q->whereNull('borrower_user_id')->where('borrower_name', $name))
            ->when($filters['contract_id'] ?? null, fn (Builder $q, $id) => $q->where('contract_id', $id))
            ->when(($filters['sort'] ?? 'created_at') === 'due_on',
                fn (Builder $q) => $q->orderByRaw('due_on '.($filters['direction'] === 'asc' ? 'asc' : 'desc').' nulls last'),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc'))
            ->orderByDesc('id');
    }

    /**
     * Limit a form query to what the user reaches with $permission (DataScope): every form (all),
     * forms of assets of the user's branch or of no branch (branch), or the user's own forms:
     * asked for by them or for them (own). Forms have no customer.
     *
     * @param  Builder<AssetCheckout>  $query
     * @return Builder<AssetCheckout>
     */
    public static function visibleTo(Builder $query, User $user, string $permission = 'asset-checkouts.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission,
            branch: fn (Builder $q, ?int $branchId) => $q->whereHas('asset', fn (Builder $q) => $q
                ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q, $id) => $q->orWhere('branch_id', $id)))),
            customer: null,
            own: fn (Builder $q) => $q->where(fn ($q) => $q->where('requested_by', $user->id)->orWhere('borrower_user_id', $user->id)));
    }

    /** Whether the user reaches the form with $permission (the check of visibleTo on one form). */
    public static function covers(AssetCheckout $checkout, User $user, string $permission): bool
    {
        return DataScope::covers($checkout, $user, $permission,
            branch: fn (AssetCheckout $c, ?int $branchId) => $c->asset !== null
                && ($c->asset->branch_id === null || (int) $c->asset->branch_id === (int) $branchId),
            customer: null,
            own: fn (AssetCheckout $c) => (int) $c->requested_by === $user->id || (int) $c->borrower_user_id === $user->id);
    }
}
