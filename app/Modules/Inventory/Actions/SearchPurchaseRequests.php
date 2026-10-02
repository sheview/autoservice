<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The purchase request list within the reach of the user's purchase-requests.view (scope own =
 * the requests they asked for, wider = everyone's; "mine" narrows to their own); search, filter, sort.
 */
class SearchPurchaseRequests
{
    public const SORTABLE = ['created_at', 'pr_no', 'needed_by'];

    /** "open" = waiting, approved, ordered or partly delivered. */
    public const STATUS_FILTERS = ['open', 'all', ...PurchaseRequest::STATUSES];

    /**
     * Work queues (the list's tabs), each instead of the status filter: to decide, to order, to
     * take deliveries of, to register what came, to hand out what was registered.
     */
    public const QUEUES = ['to_approve', 'to_order', 'to_receive', 'to_register', 'to_issue'];

    /**
     * @return array{search: string, queue: string|null, status: string, mine: bool, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'queue' => in_array($request->input('queue'), self::QUEUES, true) ? $request->input('queue') : null,
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
        $queue = $filters['queue'] ?? null;
        $status = $queue === null ? ($filters['status'] ?? 'open') : 'all';

        return self::visibleTo(PurchaseRequest::query(), $user)
            ->when($filters['mine'] ?? false, fn (Builder $q) => $q->where('requested_by', $user->id))
            // Set by the summaries (Reporting module): one person or one project.
            ->when($filters['requested_by'] ?? null, fn (Builder $q, $id) => $q->where('requested_by', $id))
            ->when($filters['contract_id'] ?? null, fn (Builder $q, $id) => $q->where('contract_id', $id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('pr_no', 'ilike', "%{$search}%")
                ->orWhere('item_name', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%")
                ->orWhere('requested_by_name', 'ilike', "%{$search}%")))
            ->when($queue !== null, fn (Builder $q) => self::inQueue($q, $queue))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', PurchaseRequest::OPEN_STATUSES))
            ->when(in_array($status, PurchaseRequest::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when(($filters['sort'] ?? 'created_at') === 'needed_by',
                fn (Builder $q) => $q->orderByRaw('needed_by '.($filters['direction'] === 'asc' ? 'asc' : 'desc').' nulls last'),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc'))
            ->orderByDesc('id');
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function inQueue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'to_approve' => $query->where('status', PurchaseRequest::STATUS_PENDING),
            'to_order' => $query->where('status', PurchaseRequest::STATUS_APPROVED),
            'to_receive' => $query->whereIn('status', [PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_PARTIALLY_RECEIVED]),
            'to_register' => $query->whereIn('status', PurchaseRequest::RECEIVING_STATUSES)->whereColumn('qty_registered', '<', 'qty_received'),
            'to_issue' => $query->whereIn('status', PurchaseRequest::RECEIVING_STATUSES)->whereColumn('qty_issued', '<', 'qty_registered'),
        };
    }

    /**
     * Narrows a purchase requests query to those the user may see (PurchaseRequestPolicy):
     * no branch or customer; own = asked for by the user.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->customer_id !== null) {
            return $query->whereRaw('false');
        }

        return DataScope::constrain($query, $user, 'purchase-requests.view', branch: null, customer: null,
            own: fn ($q) => $q->where($query->qualifyColumn('requested_by'), $user->id));
    }
}
