<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Service\Actions\TicketKpi;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Ticket KPI by person for a year (the "KPI" tab of the people summary): tickets opened and
 * fixed, fixed on time, average time to fix. Search, sort and pages on the server.
 * Same reach as the people summary: scope own = only the user themself.
 */
class SummarizeTicketKpi
{
    public const SORTABLE = ['name', 'opened', 'resolved', 'on_time_rate', 'avg_hours'];

    public function __construct(private TicketKpi $ticketKpi, private UserNames $userNames) {}

    /**
     * @return array{year: int, search: string, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $year = $request->integer('year') ?: (int) now()->year;

        return [
            'year' => max(2000, min($year, (int) now()->year + 1)),
            'search' => $request->string('search')->trim()->value(),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'resolved',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array{year: int, search: string, sort: string, direction: string}  $filters
     */
    public function handle(User $viewer, array $filters): LengthAwarePaginator
    {
        $scope = DataScope::of($viewer, SummarizePeople::PERMISSION);
        $only = match ($scope) {
            PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH => null,
            PermissionCatalog::SCOPE_OWN, PermissionCatalog::SCOPE_PROJECT => [(int) $viewer->id],
            default => [],
        };

        $figures = $only === [] ? [] : $this->ticketKpi->handle($filters['year'], $only);
        $names = $this->userNames->handle(array_keys($figures));
        $needle = mb_strtolower($filters['search']);

        $rows = collect($figures)
            ->map(fn (array $f, int $id) => [
                'user_id' => $id,
                'name' => $names[$id] ?? '#'.$id,
                ...$f,
                'on_time_rate' => $f['with_due'] > 0 ? round($f['on_time'] * 100 / $f['with_due']) : null,
            ])
            ->filter(fn (array $row) => $needle === '' || str_contains(mb_strtolower($row['name']), $needle))
            ->sortBy([[$filters['sort'], $filters['direction']], ['name', 'asc']])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, [
            'path' => route('reporting.people.kpi'),
        ]))->withQueryString();
    }
}
