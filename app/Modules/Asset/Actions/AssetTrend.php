<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assets acquired over time for the home page charts, hardware and software apart (the type of
 * the asset's category), within what the user may see (SearchAssets): per month of one calendar
 * year, and per year for the years up to it. An asset counts on its purchase date, or on the day
 * it was registered when that is not known.
 */
class AssetTrend
{
    /** The yearly chart shows this many years, ending with the chosen one. */
    public const YEARS = 5;

    private const ACQUIRED_ON = 'coalesce(assets.purchased_at, assets.created_at::date)';

    /**
     * @return array{monthly: array{hardware: list<int>, software: list<int>},
     *     yearly: array{years: list<int>, hardware: list<int>, software: list<int>}, first_year: int|null}
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
                ->join('asset_categories', 'asset_categories.id', '=', 'assets.category_id')
                ->whereRaw(self::ACQUIRED_ON.' between ? and ?', [$from->toDateString(), $yearStart->endOfYear()->toDateString()])
                ->selectRaw('extract('.$part.' from '.self::ACQUIRED_ON.')::int as k, asset_categories.asset_type as type, count(*) as total')
                ->groupBy('k', 'type')
                ->toBase()
                ->get();

            return collect(AssetCategory::ASSET_TYPES)->mapWithKeys(fn (string $type) => [
                $type => array_map(fn (int $key) => (int) ($rows->first(fn ($row) => (int) $row->k === $key && $row->type === $type)?->total ?? 0), $keys),
            ])->all();
        };

        return [
            'monthly' => $series('month', $yearStart, range(1, 12)),
            'yearly' => ['years' => $years, ...$series('year', $firstYear, $years)],
            'first_year' => $first === null ? null : CarbonImmutable::parse($first)->year,
        ];
    }
}
