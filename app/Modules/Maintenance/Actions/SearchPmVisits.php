<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The PM round list query: search + filters + sort, with the item counts.
 */
class SearchPmVisits
{
    public const SORTABLE = ['visit_no', 'due_on', 'scheduled_on', 'completed_at'];

    /** "open" = still to do, "overdue" = still to do and past due, "all" = everything, or one status */
    public const STATUS_FILTERS = ['open', 'overdue', 'all', ...PmVisit::STATUSES];

    /**
     * @return array{search: string, status: string, customer_id: int|null, assignee: string|null,
     *     month: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $assignee = (string) $request->input('assignee', '');
        $month = (string) $request->input('month', '');

        return [
            'search' => $request->string('search')->trim()->value(),
            // The list opens on the rounds still to do.
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'customer_id' => $request->integer('customer_id') ?: null,
            'assignee' => in_array($assignee, ['me', 'none'], true) || ctype_digit($assignee) ? $assignee : null,
            // YYYY-MM: rounds due in that month
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'due_on',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PmVisit>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';
        $assignee = (string) ($filters['assignee'] ?? '');
        $today = today()->toDateString();

        return self::visibleTo(PmVisit::query(), $user)
            ->with('plan:id,title')
            ->withCount([
                'items',
                'items as checked_count' => fn ($q) => $q->where('result', '!=', PmVisitItem::RESULT_PENDING),
                'items as issue_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_ISSUE),
            ])
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('visit_no', 'like', "%{$search}%")
                ->orWhereHas('plan', fn ($q) => $q->where('title', 'like', "%{$search}%"))))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', PmVisit::OPEN_STATUSES))
            ->when($status === 'overdue', fn (Builder $q) => $q->whereIn('status', PmVisit::OPEN_STATUSES)->where('due_on', '<', $today))
            ->when(in_array($status, PmVisit::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($assignee === 'me', fn (Builder $q) => $q->where('assignee_id', $user->id))
            ->when($assignee === 'none', fn (Builder $q) => $q->whereNull('assignee_id'))
            ->when(ctype_digit($assignee), fn (Builder $q) => $q->where('assignee_id', (int) $assignee))
            ->when($filters['month'] ?? null, fn (Builder $q, $month) => $q->whereBetween('due_on', [
                "{$month}-01", date('Y-m-t', strtotime("{$month}-01")),
            ]))
            ->orderBy($filters['sort'] ?? 'due_on', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }

    /**
     * The rounds the user may see with pm-visits.view (DataScope): rounds have no branch (a
     * contract's assets may be in many branches), so scope branch reaches every round; customer =
     * rounds of the account's customer; own = rounds the user is the technician of.
     *
     * @param  Builder<PmVisit>  $query
     * @return Builder<PmVisit>
     */
    public static function visibleTo(Builder $query, User $user, string $permission = 'pm-visits.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission, branch: null,
            own: fn (Builder $q) => $q->where('assignee_id', $user->id), project: 'contract_id');
    }
}
