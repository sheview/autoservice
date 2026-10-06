<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Reporting\Support\SummaryTotals;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The summary by person: everyone who borrowed, was issued or asked to buy something — users of
 * the company (by id, under their current name) and people from outside (by the name typed in) —
 * with how many of each, still open or not. Search, filters, sort and pages on the server.
 * With summary-people.view scope own, only the user themself; scope customer reaches nobody.
 */
class SummarizePeople
{
    public const SORTABLE = ['name', 'last_at', 'open_count', 'total'];

    public const KINDS = ['issue', 'loan', 'purchase'];

    public const PERMISSION = 'summary-people.view';

    public function __construct(
        private SummaryRows $rows,
        private UserNames $userNames,
    ) {}

    /**
     * @return array{search: string, show: string, kind: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'show' => $request->input('show') === 'open' ? 'open' : 'all',
            'kind' => in_array($request->input('kind'), self::KINDS, true) ? $request->input('kind') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'last_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Whether the user may open the summary of one person: a user of the company ($userId) or
     * someone from outside (null). With scope own, only of themself.
     */
    public static function reaches(User $viewer, ?int $userId): bool
    {
        return match (DataScope::of($viewer, self::PERMISSION)) {
            PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH => true,
            PermissionCatalog::SCOPE_OWN => $userId !== null && $userId === (int) $viewer->id,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     */
    public function handle(User $viewer, array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? '';
        $scope = DataScope::of($viewer, self::PERMISSION);

        $people = DB::query()->fromSub($this->rows->handle($viewer), 'rows')
            ->selectRaw('user_id, case when user_id is null then name end as outside_name, max(name) as name, '.SummaryTotals::SELECT)
            ->when($scope === PermissionCatalog::SCOPE_OWN, fn ($q) => $q->where('user_id', $viewer->id))
            ->unless(in_array($scope, [PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH, PermissionCatalog::SCOPE_OWN], true),
                fn ($q) => $q->whereRaw('false'))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->groupByRaw('user_id, case when user_id is null then name end')
            ->when(($filters['show'] ?? 'all') === 'open', fn ($q) => $q->havingRaw('count(case when is_open = 1 then 1 end) > 0'))
            ->orderBy($filters['sort'] ?? 'last_at', $filters['direction'] ?? 'desc')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $names = $this->userNames->handle($people->getCollection()->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all());

        return $people->through(fn (object $row) => [
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'outside_name' => $row->outside_name,
            'name' => $row->user_id === null ? $row->name : ($names[$row->user_id] ?? $row->name),
            ...SummaryTotals::of($row),
        ]);
    }
}
