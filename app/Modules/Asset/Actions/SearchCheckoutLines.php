<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The line tabs of the issue/loan pages (SearchCheckoutRequests::TABS): "backorders" = lines
 * still owed on requests in progress, by the date they are needed; "returns" = lent assets not
 * back yet (?overdue = past their due date only). Within the requests the user reaches with
 * asset-checkouts.fulfill / .return.
 */
class SearchCheckoutLines
{
    /**
     * Lines for the tabs backorders / returns.
     *
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<CheckoutItem>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $returns = ($filters['tab'] ?? 'backorders') === 'returns';

        return CheckoutItem::query()
            ->select('checkout_items.*')
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->whereIn('checkout_items.request_id', SearchCheckoutRequests::visibleTo(CheckoutRequest::query(), $user, $returns ? 'asset-checkouts.return' : 'asset-checkouts.fulfill')->select('id'))
            ->with('request')
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('checkout_items.item_name', 'ilike', "%{$search}%")
                ->orWhere('checkout_items.item_code', 'ilike', "%{$search}%")
                ->orWhere('checkout_requests.request_no', 'ilike', "%{$search}%")
                ->orWhere('checkout_requests.borrower_name', 'ilike', "%{$search}%")))
            ->when(! $returns, fn (Builder $q) => $q
                ->whereIn('checkout_requests.status', CheckoutRequest::OPEN_STATUSES)
                ->whereIn('checkout_items.status', [CheckoutItem::STATUS_BACKORDERED, CheckoutItem::STATUS_PARTIAL])
                ->orderByRaw("checkout_requests.needed_by {$direction} nulls last"))
            ->when($returns, fn (Builder $q) => $q
                ->where('checkout_items.item_type', CheckoutItem::TYPE_ASSET)
                ->where('checkout_items.checkout_type', CheckoutItem::LOAN)
                ->whereColumn('checkout_items.qty_fulfilled', '>', 'checkout_items.qty_returned')
                ->when($filters['overdue'] ?? false, fn ($q) => $q->where('checkout_items.due_return_date', '<', today()->toDateString()))
                ->orderByRaw("checkout_items.due_return_date {$direction} nulls last"))
            ->orderBy('checkout_items.id');
    }
}
