<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCheckout;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One row per part issue/loan form, in the shape the summaries by person and by project
 * (Reporting module) add up — the same as the Asset module's CheckoutSummaryRows. Open = waiting,
 * or lent and not back (issued parts are used up). Rejected and cancelled forms are left out.
 */
class PartCheckoutSummaryRows
{
    public function handle(): QueryBuilder
    {
        return PartCheckout::query()
            ->whereNotIn('status', [PartCheckout::STATUS_REJECTED, PartCheckout::STATUS_CANCELLED])
            ->select(['borrower_user_id as user_id', 'borrower_name as name', 'contract_id', 'type as kind'])
            ->selectRaw('case when status = ? or (status = ? and type = ?) then 1 else 0 end as is_open',
                [PartCheckout::STATUS_PENDING, PartCheckout::STATUS_APPROVED, PartCheckout::TYPE_LOAN])
            ->selectRaw('quantity, null::bigint as amount, created_at as at')
            ->toBase();
    }
}
