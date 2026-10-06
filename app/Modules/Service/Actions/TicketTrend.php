<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Repair work over time for the home page charts, within what the user may see (SearchTickets):
 * tickets opened (created_at) and closed (closed_at) per month of one calendar year, and per year
 * for the years up to it. A new year starts from zero.
 */
class TicketTrend
{
    /** The yearly chart shows this many years, ending with the chosen one. */
    public const YEARS = 5;

    /**
     * @return array{monthly: array{opened: list<int>, closed: list<int>},
     *     yearly: array{years: list<int>, opened: list<int>, closed: list<int>}, first_year: int|null}
     */
    public function handle(User $user, int $year): array
    {
        $visible = fn (): Builder => SearchTickets::visibleTo(Ticket::query(), $user);
        $yearStart = CarbonImmutable::create($year)->startOfYear();
        $firstYear = $yearStart->subYears(self::YEARS - 1);
        $years = range($firstYear->year, $year);
        $first = $visible()->min('created_at');

        $series = function (string $column, string $part, CarbonImmutable $from, array $keys) use ($visible, $yearStart): array {
            $counts = $visible()
                ->whereBetween($column, [$from, $yearStart->endOfYear()])
                ->selectRaw("extract({$part} from {$column}) as k, count(*) as total")
                ->groupBy('k')
                ->pluck('total', 'k');

            return array_map(fn (int $key) => (int) ($counts[$key] ?? 0), $keys);
        };

        return [
            'monthly' => [
                'opened' => $series('created_at', 'month', $yearStart, range(1, 12)),
                'closed' => $series('closed_at', 'month', $yearStart, range(1, 12)),
            ],
            'yearly' => [
                'years' => $years,
                'opened' => $series('created_at', 'year', $firstYear, $years),
                'closed' => $series('closed_at', 'year', $firstYear, $years),
            ],
            'first_year' => $first === null ? null : CarbonImmutable::parse($first)->year,
        ];
    }
}
