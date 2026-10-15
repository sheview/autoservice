<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Actions\PmOverview;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PM Dashboard and PM Reports, staff only, over the work orders the user may see:
 *
 *   dashboard  where the work stands now (by stage, overdue, due this month), what waits for the
 *              user (to review, to approve, to publish), the last 12 months, the latest history
 *   reports    a period (default this year to date) by figures and by customer, with the work
 *              orders of the period (search, customer and status filters, sort, paging)
 */
class PmDashboardController extends Controller
{
    private const SORTABLE = ['due_on', 'visit_no', 'completed_at'];

    /** Customer names by id, read once per request. */
    private ?Collection $customerNames = null;

    public function __construct(private PmOverview $overview, private ListCustomers $listCustomers) {}

    public function dashboard(Request $request): Response
    {
        $user = $this->staff($request);
        $waiting = fn (array $statuses, string $ability) => $this->overview->visible($user)
            ->whereIn('status', $statuses)->orderBy('due_on')->limit(8)->get()
            ->filter(fn (PmVisit $visit) => $user->can($ability, $visit))
            ->map(fn (PmVisit $visit) => $this->row($visit))->values();

        return Inertia::render('Maintenance/Dashboard', [
            'counts' => $this->overview->counts($user),
            'thisMonth' => $this->overview->period($user, today()->toImmutable()->startOfMonth(), today()->toImmutable()->endOfMonth()),
            'trend' => $this->overview->trend($user),
            'waiting' => [
                'review' => $waiting([PmVisit::STATUS_SUBMITTED], 'review'),
                'approve' => $waiting([PmVisit::STATUS_PENDING_APPROVAL], 'approve'),
                'publish' => $waiting([PmVisit::STATUS_APPROVED], 'publish'),
                'overdue' => $this->overview->visible($user)->whereIn('status', PmVisit::OPEN_STATUSES)
                    ->where('due_on', '<', today()->toDateString())->orderBy('due_on')->limit(8)->get()
                    ->map(fn (PmVisit $visit) => $this->row($visit)),
            ],
            // The latest steps of the work orders the user may see.
            'history' => PmVisitEvent::query()
                ->whereIn('pm_visit_id', $this->overview->visible($user)->select('id'))
                ->with('visit:id,ulid,visit_no')
                ->latest('id')->limit(12)->get()
                ->map(fn (PmVisitEvent $event) => [
                    ...$event->only(['id', 'type', 'note', 'user_name']),
                    'visit' => $event->visit?->only(['ulid', 'visit_no']),
                    'at' => $event->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function reports(Request $request): Response
    {
        $user = $this->staff($request);
        $tz = config('app.timezone');
        $from = $this->date($request->input('from')) ?? CarbonImmutable::now($tz)->startOfYear();
        $to = $this->date($request->input('to')) ?? CarbonImmutable::now($tz)->endOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $filters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'customer_id' => $request->integer('customer_id') ?: null,
            'status' => in_array($request->input('status'), PmVisit::STATUSES, true) ? $request->input('status') : null,
            'search' => $request->string('search')->trim()->value(),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'due_on',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
        $customers = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        $visits = $this->overview->visible($user)
            ->with('plan:id,title')
            ->whereBetween('due_on', [$filters['from'], $filters['to']])
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('visit_no', 'like', "%{$filters['search']}%")
                ->orWhere('title', 'like', "%{$filters['search']}%")
                ->orWhereHas('plan', fn ($q) => $q->where('title', 'like', "%{$filters['search']}%"))))
            ->withCount(['items', 'items as issue_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_FAILED)])
            ->orderBy($filters['sort'], $filters['direction'])->orderBy('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Maintenance/Reports', [
            'filters' => $filters,
            'summary' => $this->overview->period($user, $from, $to, $filters['customer_id']),
            'byCustomer' => collect($this->overview->customers($user, $from, $to))
                ->map(fn (array $row, $customerId) => ['customer' => $customers[$customerId] ?? '-', ...$row])
                ->sortByDesc('due')->values(),
            'visits' => $visits->through(fn (PmVisit $visit) => [
                ...$this->row($visit),
                ...$visit->only(['items_count', 'issue_count', 'current_version']),
                'completed_at' => $visit->completed_at?->toIso8601String(),
            ]),
            'customers' => $this->listCustomers->handle(),
            'statuses' => PmVisit::STATUSES,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(PmVisit $visit): array
    {
        $customers = $this->customerNames ??= collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        return [
            ...$visit->only(['ulid', 'visit_no', 'status']),
            'title' => $visit->displayTitle(),
            'customer' => $customers[$visit->customer_id] ?? null,
            'due_on' => $visit->due_on->toDateString(),
            'overdue' => $visit->isOverdue(),
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, config('app.timezone'))->startOfDay()
            : null;
    }

    private function staff(Request $request)
    {
        Gate::authorize('viewAny', PmVisit::class);
        abort_if($request->user()->customer_id !== null, 403);

        return $request->user();
    }
}
