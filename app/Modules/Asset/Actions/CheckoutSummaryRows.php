<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One row per issue/loan form of the assets the user may see, in the shape the summaries by person
 * and by project (Reporting module) add up: who, which project, kind (issue | loan), still open,
 * how many and when. Rejected and cancelled forms never went out, so they are left out. Purchases come in the same shape from the Inventory module.
 */
class CheckoutSummaryRows
{
    public function handle(User $viewer): QueryBuilder
    {
        $open = implode(', ', array_fill(0, count(AssetCheckout::OPEN_STATUSES), '?'));

        return AssetCheckout::query()
            ->whereNotIn('status', [AssetCheckout::STATUS_REJECTED, AssetCheckout::STATUS_CANCELLED])
            ->whereHas('asset', fn (Builder $q) => SearchAssets::visibleTo($q, $viewer))
            ->select(['borrower_user_id as user_id', 'borrower_name as name', 'contract_id', 'type as kind'])
            ->selectRaw("case when status in ({$open}) then 1 else 0 end as is_open", AssetCheckout::OPEN_STATUSES)
            ->selectRaw('quantity, null::bigint as amount, created_at as at')
            ->toBase();
    }
}
