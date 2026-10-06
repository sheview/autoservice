<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One row per issue/loan request line the user may see, in the shape the summaries by person and
 * by project (Reporting module) add up: who, which project, kind (issue | loan for assets; parts
 * are always issue), still open, how many and when. Open = still to decide or hand out, or lent
 * and not back. Drafts, and rejected or cancelled lines, never went out: they are left out.
 * Purchases come in the same shape from the Inventory module.
 */
class CheckoutSummaryRows
{
    /**
     * @param  list<string>  $itemTypes  CheckoutItem::TYPE_ASSET / TYPE_PART
     */
    public function handle(User $viewer, array $itemTypes = [CheckoutItem::TYPE_ASSET, CheckoutItem::TYPE_PART]): QueryBuilder
    {
        $finished = implode(', ', array_fill(0, count(CheckoutItem::FINISHED), '?'));

        return CheckoutItem::query()
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->whereIn('checkout_items.request_id', SearchCheckoutRequests::visibleTo(CheckoutRequest::query(), $viewer)->select('checkout_requests.id'))
            ->whereNotIn('checkout_requests.status', [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_REJECTED, CheckoutRequest::STATUS_CANCELLED])
            ->whereNotIn('checkout_items.status', [CheckoutItem::STATUS_REJECTED, CheckoutItem::STATUS_CANCELLED])
            ->whereIn('checkout_items.item_type', $itemTypes === [] ? [''] : $itemTypes)
            ->selectRaw('checkout_requests.borrower_user_id as user_id, checkout_requests.borrower_name as name, checkout_requests.contract_id')
            ->selectRaw("case when checkout_items.item_type = 'part' then 'issue' else checkout_items.checkout_type end as kind")
            ->selectRaw("case when checkout_items.status not in ({$finished})
                or (checkout_items.checkout_type = 'loan' and checkout_items.qty_fulfilled > checkout_items.qty_returned) then 1 else 0 end as is_open", CheckoutItem::FINISHED)
            ->selectRaw('coalesce(checkout_items.qty_approved, checkout_items.qty_requested) as quantity, null as amount')
            ->selectRaw('coalesce(checkout_requests.submitted_at, checkout_requests.created_at) as at')
            ->toBase();
    }
}
