<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Policies\PurchaseRequestPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The purchase request list: the user's own, or all for approvers and buyers; search, filter, sort.
 */
class SearchPurchaseRequests
{
    public const SORTABLE = ['created_at', 'pr_no', 'needed_by'];

    /** "open" = waiting, approved or ordered. */
    public const STATUS_FILTERS = ['open', 'all', ...PurchaseRequest::STATUSES];

    /**
     * @return array{search: string, status: string, mine: bool, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'mine' => $request->boolean('mine'),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PurchaseRequest>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';
        $onlyMine = ! PurchaseRequestPolicy::seesAll($user) || ($filters['mine'] ?? false);

        return PurchaseRequest::query()
            ->when($onlyMine, fn (Builder $q) => $q->where('requested_by', $user->id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('pr_no', 'ilike', "%{$search}%")
                ->orWhere('item_name', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%")
                ->orWhere('requested_by_name', 'ilike', "%{$search}%")))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', PurchaseRequest::OPEN_STATUSES))
            ->when(in_array($status, PurchaseRequest::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when(($filters['sort'] ?? 'created_at') === 'needed_by',
                fn (Builder $q) => $q->orderByRaw('needed_by '.($filters['direction'] === 'asc' ? 'asc' : 'desc').' nulls last'),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc'))
            ->orderByDesc('id');
    }
}
