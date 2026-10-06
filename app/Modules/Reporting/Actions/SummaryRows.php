<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Asset\Actions\CheckoutSummaryRows;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PurchaseSummaryRows;
use App\Modules\Platform\Support\Modules;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every issue, loan (request lines of assets and of parts) and purchase the user may see, one row
 * each (user_id, name, contract_id, kind, is_open, quantity, amount, at), from the modules switched
 * on — for the summaries by person and by project to add up. Without $purchases, no purchase rows
 * (customer accounts never see them).
 */
class SummaryRows
{
    public function __construct(
        private Modules $modules,
        private CheckoutSummaryRows $checkouts,
        private PurchaseSummaryRows $purchases,
    ) {}

    public function handle(User $viewer, bool $purchases = true): Builder
    {
        // Requests live in the Asset module; their part lines need the Inventory module too.
        $lineTypes = array_values(array_filter([
            CheckoutItem::TYPE_ASSET,
            $this->modules->enabled('inventory') ? CheckoutItem::TYPE_PART : null,
        ]));

        $parts = array_values(array_filter([
            $this->modules->enabled('asset') ? $this->checkouts->handle($viewer, $lineTypes) : null,
            $purchases && $this->modules->enabled('inventory') ? $this->purchases->handle($viewer) : null,
        ]));

        if ($parts === []) {
            // Neither module on: the same columns, no rows.
            return DB::query()->selectRaw('null as user_id, null as name, null as contract_id, null as kind,
                0 as is_open, 0 as quantity, null as amount, null as at')->whereRaw('false');
        }

        return collect($parts)->slice(1)->reduce(fn (Builder $union, Builder $part) => $union->unionAll($part), $parts[0]);
    }
}
