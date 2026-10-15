<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Actions\SearchPmVisits;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer Reports: the PM reports given to customers (published), newest first, with search,
 * customer and month filters, sort and paging on the server. What pm-reports.view reaches: a
 * customer account sees only its own customer's. Staff also see how many links are live.
 */
class PmCustomerReportController extends Controller
{
    private const SORTABLE = ['published_at', 'visit_no', 'due_on'];

    public function index(Request $request, ListCustomers $listCustomers): Response
    {
        Gate::authorize('pm-reports.view');

        $user = $request->user();
        $staff = $user->customer_id === null;
        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'customer_id' => $staff ? ($request->integer('customer_id') ?: null) : null,
            'month' => preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'published_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];

        $reports = SearchPmVisits::visibleTo(PmVisit::query(), $user, 'pm-reports.view')
            ->with('plan:id,title')
            ->where('published_version', '>', 0)
            ->where('status', '!=', PmVisit::STATUS_CANCELLED)
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('visit_no', 'like', "%{$filters['search']}%")
                ->orWhere('title', 'like', "%{$filters['search']}%")
                ->orWhereHas('plan', fn ($q) => $q->where('title', 'like', "%{$filters['search']}%"))))
            ->when($filters['customer_id'], fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['month'], fn (Builder $q, $month) => $q->whereBetween('published_at', [$month.'-01 00:00:00', date('Y-m-t 23:59:59', strtotime($month.'-01'))]))
            ->when($staff, fn (Builder $q) => $q->withCount(['shares as live_shares' => fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now())]))
            ->withCount(['items', 'items as issue_count' => fn ($q) => $q->where('result', 'issue')])
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $customers = collect($listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        return Inertia::render('Maintenance/CustomerReports', [
            'reports' => $reports->through(fn (PmVisit $visit) => [
                ...$visit->only(['ulid', 'visit_no', 'published_version', 'items_count', 'issue_count']),
                // Staff only: how many of its customer links still work.
                ...($staff ? ['live_shares' => $visit->live_shares] : []),
                'title' => $visit->displayTitle(),
                'customer' => $customers[$visit->customer_id] ?? null,
                'due_on' => $visit->due_on->toDateString(),
                'published_at' => $visit->published_at?->toIso8601String(),
                'can_view' => $user->can('view', $visit),
            ]),
            'filters' => $filters,
            'customers' => $staff ? $listCustomers->handle() : [],
            'staff' => $staff,
        ]);
    }
}
