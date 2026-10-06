<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The PM plan list query: search + filters + sort, with the round counts.
 */
class SearchPmPlans
{
    public const SORTABLE = ['title', 'starts_on', 'ends_on', 'interval_months', 'created_at'];

    /**
     * @return array{search: string, customer_id: int|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'customer_id' => $request->integer('customer_id') ?: null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'ends_on',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PmPlan>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return self::visibleTo(PmPlan::query(), $user)
            ->withCount([
                'visits',
                'visits as completed_count' => fn ($q) => $q->where('status', PmVisit::STATUS_COMPLETED),
                'visits as overdue_count' => fn ($q) => $q->whereIn('status', PmVisit::OPEN_STATUSES)->where('due_on', '<', today()->toDateString()),
            ])
            ->when($search !== '', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->orderBy($filters['sort'] ?? 'ends_on', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }

    /**
     * The plans the user may see with pm-plans.view (DataScope): plans have no branch, so scope
     * branch reaches every plan; customer = plans of the account's customer; own = plans the user
     * is the technician of.
     *
     * @param  Builder<PmPlan>  $query
     * @return Builder<PmPlan>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        return DataScope::constrain($query, $user, 'pm-plans.view', branch: null,
            own: fn (Builder $q) => $q->where('assignee_id', $user->id));
    }
}
