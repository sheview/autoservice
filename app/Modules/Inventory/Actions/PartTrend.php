<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * Parts received into stock over time for the home page charts, beside the assets acquired:
 * the quantity received per month of one calendar year, and per year for the years up to it.
 */
class PartTrend
{
    /**
     * @param  list<int>  $years  the years of the yearly chart, oldest first
     * @return array{monthly: list<int>, yearly: list<int>, first_year: int|null}
     */
    public function handle(int $year, array $years): array
    {
        $series = function (string $part, CarbonImmutable $from, array $keys) use ($year): array {
            $rows = StockMovement::query()
                ->where('type', StockMovement::TYPE_RECEIVE)
                ->whereBetween('created_at', [$from, CarbonImmutable::create($year)->endOfYear()])
                ->selectRaw("extract({$part} from created_at) as k, sum(quantity) as total")
                ->groupBy('k')
                ->toBase()
                ->pluck('total', 'k');

            return array_map(fn (int $key) => (int) ($rows[$key] ?? 0), $keys);
        };
        $first = StockMovement::query()->where('type', StockMovement::TYPE_RECEIVE)->min('created_at');

        return [
            'monthly' => $series('month', CarbonImmutable::create($year)->startOfYear(), range(1, 12)),
            'yearly' => $series('year', CarbonImmutable::create($years[0])->startOfYear(), $years),
            'first_year' => $first === null ? null : CarbonImmutable::parse($first)->year,
        ];
    }
}
