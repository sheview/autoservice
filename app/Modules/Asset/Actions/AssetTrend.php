<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assets acquired over time for the home page charts, within what the user may see
 * (SearchAssets): per month of one calendar year, and per year for the years up to it. An asset
 * counts its quantity (a lot of 10 cables is 10) on its purchase date, or on the day it was
 * registered when that is not known. Parts received go beside it (Inventory module, PartTrend).
 */
class AssetTrend
{
    /** The yearly chart shows this many years, ending with the chosen one. */
    public const YEARS = 5;

    private const ACQUIRED_ON = 'coalesce(assets.purchased_at, assets.created_at::date)';

    /**
     * @return array{monthly: list<int>, yearly: array{years: list<int>, counts: list<int>}, first_year: int|null}
     */
    public function handle(User $user, int $year): array
    {
        $visible = fn (): Builder => SearchAssets::visibleTo(Asset::query(), $user);
        $yearStart = CarbonImmutable::create($year)->startOfYear();
        $firstYear = $yearStart->subYears(self::YEARS - 1);
        $years = range($firstYear->year, $year);
        $first = $visible()->toBase()->min(DB::raw(self::ACQUIRED_ON));

        $series = function (string $part, CarbonImmutable $from, array $keys) use ($visible, $yearStart): array {
            $rows = $visible()
                ->whereRaw(self::ACQUIRED_ON.' between ? and ?', [$from->toDateString(), $yearStart->endOfYear()->toDateString()])
                ->selectRaw('extract('.$part.' from '.self::ACQUIRED_ON.')::int as k, sum(assets.quantity) as total')
                ->groupBy('k')
                ->toBase()
                ->pluck('total', 'k');

            return array_map(fn (int $key) => (int) ($rows[$key] ?? 0), $keys);
        };

        return [
            'monthly' => $series('month', $yearStart, range(1, 12)),
            'yearly' => ['years' => $years, 'counts' => $series('year', $firstYear, $years)],
            'first_year' => $first === null ? null : CarbonImmutable::parse($first)->year,
        ];
    }
}
