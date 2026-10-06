<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The issue/loan request lines of a person or a project (summary pages, Reporting module): the
 * same lines CheckoutSummaryRows counts (sent, not rejected or cancelled, within what the user may
 * see), searched, open-or-all, newest or oldest first. Rows: ItemRequestLines::row().
 */
class SearchSummaryLines
{
    /**
     * @param  array{search?: string, status?: string, direction?: string, borrower_user_id?: int|null,
     *     borrower_name?: string|null, contract_id?: int|null}  $filters
     * @param  string  $itemType  CheckoutItem::TYPE_ASSET | TYPE_PART
     * @return Builder<CheckoutItem>
     */
    public function handle(User $viewer, array $filters, string $itemType): Builder
    {
        $search = $filters['search'] ?? '';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return CheckoutItem::query()
            ->select('checkout_items.*')
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->whereIn('checkout_items.request_id', SearchCheckoutRequests::visibleTo(CheckoutRequest::query(), $viewer)->select('checkout_requests.id'))
            ->whereNotIn('checkout_requests.status', [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_REJECTED, CheckoutRequest::STATUS_CANCELLED])
            ->whereNotIn('checkout_items.status', [CheckoutItem::STATUS_REJECTED, CheckoutItem::STATUS_CANCELLED])
            ->where('checkout_items.item_type', $itemType)
            ->when($filters['borrower_user_id'] ?? null, fn (Builder $q, $id) => $q->where('checkout_requests.borrower_user_id', $id))
            ->when($filters['borrower_name'] ?? null, fn (Builder $q, $name) => $q->whereNull('checkout_requests.borrower_user_id')->where('checkout_requests.borrower_name', $name))
            ->when($filters['contract_id'] ?? null, fn (Builder $q, $id) => $q->where('checkout_requests.contract_id', $id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('checkout_items.item_name', 'like', "%{$search}%")
                ->orWhere('checkout_items.item_code', 'like', "%{$search}%")
                ->orWhere('checkout_requests.request_no', 'like', "%{$search}%")
                ->orWhere('checkout_requests.borrower_name', 'like', "%{$search}%")
                ->orWhere('checkout_requests.purpose', 'like', "%{$search}%")))
            ->when(($filters['status'] ?? 'all') === 'open', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereNotIn('checkout_items.status', CheckoutItem::FINISHED)
                ->orWhere(fn ($q) => $q->where('checkout_items.checkout_type', CheckoutItem::LOAN)
                    ->whereColumn('checkout_items.qty_fulfilled', '>', 'checkout_items.qty_returned'))))
            ->with(['request', 'asset:id,ulid'])
            ->orderBy('checkout_requests.created_at', $direction)
            ->orderBy('checkout_items.id', $direction);
    }
}
