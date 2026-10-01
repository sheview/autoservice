<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Support\SummaryTotals;
use Illuminate\Support\Facades\DB;

/**
 * The totals of one person (a user, or a name from outside) or one project, for the top of its
 * summary page. Without $purchases, purchases are neither counted nor returned.
 */
class CountSummaryRows
{
    public function __construct(private SummaryRows $rows) {}

    /**
     * @param  array{user_id?: int, outside_name?: string, contract_id?: int}  $of
     * @return array<string, mixed> see SummaryTotals::of()
     */
    public function handle(User $viewer, array $of, bool $purchases = true): array
    {
        $row = DB::query()->fromSub($this->rows->handle($viewer, $purchases), 'rows')
            ->selectRaw(SummaryTotals::SELECT)
            ->when($of['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($of['outside_name'] ?? null, fn ($q, $name) => $q->whereNull('user_id')->where('name', $name))
            ->when($of['contract_id'] ?? null, fn ($q, $id) => $q->where('contract_id', $id))
            ->first();

        return SummaryTotals::of($row, $purchases);
    }
}
