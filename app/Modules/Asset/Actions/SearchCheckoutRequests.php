<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The issue/loan pages, server-side search, filters, sort and pages, within what the user may
 * see (visibleTo). By tab:
 *
 *   requests    every request (default: those still in progress)
 *   approve     waiting for approval (asset-checkouts.approve)
 *   fulfill     approved, with lines still to hand out (asset-checkouts.fulfill)
 *   backorders  the lines of every request that are still owed, by the date they are needed
 *   returns     lent assets not back yet (overdue first)
 *
 * The first three list requests (here); the last two list lines (SearchCheckoutLines).
 */
class SearchCheckoutRequests
{
    public const TABS = ['requests', 'approve', 'fulfill', 'backorders', 'returns'];

    public const LINE_TABS = ['backorders', 'returns'];

    public const SORTABLE = ['created_at', 'request_no', 'needed_by'];

    /** "open" = in progress; or one status. */
    public const STATUS_FILTERS = ['open', 'all', ...CheckoutRequest::STATUSES];

    /**
     * @return array{tab: string, search: string, status: string, overdue: bool, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'requests';

        return [
            'tab' => $tab,
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'overdue' => $request->boolean('overdue'),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : ($tab === 'requests' ? 'created_at' : 'needed_by'),
            'direction' => $request->input('direction') === 'desc' || ($tab === 'requests' && $request->input('direction') !== 'asc') ? 'desc' : 'asc',
        ];
    }

    /**
     * Requests for the tabs requests / approve / fulfill.
     *
     * @param  array<string, mixed>  $filters  from filtersFrom(); plus borrower_user_id / borrower_name / contract_id (summaries)
     * @return Builder<CheckoutRequest>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';
        $tab = $filters['tab'] ?? 'requests';
        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return self::visibleTo(CheckoutRequest::query(), $user, match ($tab) {
            'approve' => 'asset-checkouts.approve',
            'fulfill' => 'asset-checkouts.fulfill',
            default => 'asset-checkouts.view',
        })
            ->with('items')
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('request_no', 'ilike', "%{$search}%")
                ->orWhere('borrower_name', 'ilike', "%{$search}%")
                ->orWhere('requester_name', 'ilike', "%{$search}%")
                ->orWhere('purpose', 'ilike', "%{$search}%")
                ->orWhereHas('items', fn ($q) => $q->where('item_name', 'ilike', "%{$search}%")->orWhere('item_code', 'ilike', "%{$search}%"))))
            ->when($tab === 'approve', fn (Builder $q) => $q->where('status', CheckoutRequest::STATUS_PENDING))
            ->when($tab === 'fulfill', fn (Builder $q) => $q->whereIn('status', [CheckoutRequest::STATUS_APPROVED, CheckoutRequest::STATUS_PARTIAL])
                ->whereHas('items', fn ($q) => $q->whereIn('status', [CheckoutItem::STATUS_APPROVED, CheckoutItem::STATUS_PARTIAL])))
            ->when($tab === 'requests' && $status === 'open', fn (Builder $q) => $q->whereIn('status', [CheckoutRequest::STATUS_DRAFT, ...CheckoutRequest::OPEN_STATUSES, CheckoutRequest::STATUS_FULFILLED]))
            ->when($tab === 'requests' && in_array($status, CheckoutRequest::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            // A draft is only its writer's.
            ->where(fn ($q) => $q->where('status', '!=', CheckoutRequest::STATUS_DRAFT)->orWhere('requester_id', $user->id))
            ->when($filters['borrower_user_id'] ?? null, fn (Builder $q, $id) => $q->where('borrower_user_id', $id))
            ->when($filters['borrower_name'] ?? null, fn (Builder $q, $name) => $q->whereNull('borrower_user_id')->where('borrower_name', $name))
            ->when($filters['contract_id'] ?? null, fn (Builder $q, $id) => $q->where('contract_id', $id))
            ->when($sort === 'needed_by',
                fn (Builder $q) => $q->orderByRaw("needed_by {$direction} nulls last"),
                fn (Builder $q) => $q->orderBy($sort, $direction))
            ->orderByDesc('id');
    }

    /**
     * Narrows a requests query to those the user reaches with $permission: scope all, branch
     * (branch_id) or own (asked for by the user or made out to them). Customer accounts reach none.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function visibleTo(Builder $query, User $user, string $permission = 'asset-checkouts.view'): Builder
    {
        if ($user->customer_id !== null) {
            return $query->whereRaw('false');
        }

        $own = fn (Builder $q) => $q->where($q->qualifyColumn('requester_id'), $user->id)->orWhere($q->qualifyColumn('borrower_user_id'), $user->id);

        // Without view, whoever may ask still sees their own requests.
        if ($permission === 'asset-checkouts.view' && DataScope::of($user, $permission) === null
            && ($user->can('asset-checkouts.request') || $user->can('asset-checkouts.create'))) {
            return $query->where(fn ($q) => $own($q));
        }

        return DataScope::constrain($query, $user, $permission, branch: $query->qualifyColumn('branch_id'), customer: null, own: $own);
    }
}
