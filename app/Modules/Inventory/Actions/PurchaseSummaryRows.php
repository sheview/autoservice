<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One row per purchase request the user may see (SearchPurchaseRequests::visibleTo), in the shape the
 * summaries by person and by project (Reporting module) add up — the same as CheckoutSummaryRows
 * of the Asset module, with kind "purchase" and the amount in satang when priced. Rejected and
 * cancelled requests bought nothing, so they are left out.
 */
class PurchaseSummaryRows
{
    public function handle(User $viewer): QueryBuilder
    {
        $open = implode(', ', array_fill(0, count(PurchaseRequest::OPEN_STATUSES), '?'));

        return SearchPurchaseRequests::visibleTo(PurchaseRequest::query(), $viewer)
            ->whereNotIn('status', [PurchaseRequest::STATUS_REJECTED, PurchaseRequest::STATUS_CANCELLED])
            ->select(['requested_by as user_id', 'requested_by_name as name', 'contract_id'])
            ->selectRaw("'purchase' as kind")
            ->selectRaw("case when status in ({$open}) then 1 else 0 end as is_open", PurchaseRequest::OPEN_STATUSES)
            ->selectRaw('quantity, unit_price * quantity as amount, created_at as at')
            ->toBase();
    }
}
