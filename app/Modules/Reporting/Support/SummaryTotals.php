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
    public const SELECT = "count(case when kind = 'issue' then 1 end) as issues,
        count(case when kind = 'issue' and is_open = 1 then 1 end) as issues_open,
        count(case when kind = 'loan' then 1 end) as loans,
        count(case when kind = 'loan' and is_open = 1 then 1 end) as loans_open,
        count(case when kind = 'purchase' then 1 end) as purchases,
        count(case when kind = 'purchase' and is_open = 1 then 1 end) as purchases_open,
        coalesce(sum(case when kind = 'purchase' then amount end), 0) as purchase_amount,
        count(case when is_open = 1 then 1 end) as open_count,
        count(*) as total,
        max(at) as last_at";

    /** The keys about purchases, left out for those who may not see purchases or amounts. */
    public const PURCHASE_KEYS = ['purchases', 'purchases_open', 'purchase_amount'];

    /**
     * @return array{issues: int, issues_open: int, loans: int, loans_open: int, purchases?: int, purchases_open?: int,
     *     purchase_amount?: string, open_count: int, total: int, last_at: string|null}
     */
    public static function of(?object $row, bool $purchases = true): array
    {
        $count = fn (string $key) => (int) ($row->{$key} ?? 0);

        $totals = [
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

        return $purchases ? $totals : array_diff_key($totals, array_flip(self::PURCHASE_KEYS));
    }
}
