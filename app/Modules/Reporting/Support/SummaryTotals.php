<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Platform\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The counts the summaries show for a person or a project, added up over SummaryRows: issues,
 * loans and purchases (all, and those still open), the amount bought and the latest date.
 */
class SummaryTotals
{
    public const SELECT = "count(*) filter (where kind = 'issue') as issues,
        count(*) filter (where kind = 'issue' and is_open = 1) as issues_open,
        count(*) filter (where kind = 'loan') as loans,
        count(*) filter (where kind = 'loan' and is_open = 1) as loans_open,
        count(*) filter (where kind = 'purchase') as purchases,
        count(*) filter (where kind = 'purchase' and is_open = 1) as purchases_open,
        coalesce(sum(amount) filter (where kind = 'purchase'), 0) as purchase_amount,
        count(*) filter (where is_open = 1) as open_count,
        count(*) as total,
        max(at) as last_at";

    /**
     * @return array{issues: int, issues_open: int, loans: int, loans_open: int, purchases: int, purchases_open: int,
     *     purchase_amount: string, open_count: int, total: int, last_at: string|null}
     */
    public static function of(?object $row): array
    {
        $count = fn (string $key) => (int) ($row->{$key} ?? 0);

        return [
            'issues' => $count('issues'),
            'issues_open' => $count('issues_open'),
            'loans' => $count('loans'),
            'loans_open' => $count('loans_open'),
            'purchases' => $count('purchases'),
            'purchases_open' => $count('purchases_open'),
            'purchase_amount' => Money::toBaht($count('purchase_amount')),
            'open_count' => $count('open_count'),
            'total' => $count('total'),
            'last_at' => isset($row->last_at) ? Carbon::parse($row->last_at)->toIso8601String() : null,
        ];
    }
}
