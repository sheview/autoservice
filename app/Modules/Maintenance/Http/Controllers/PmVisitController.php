<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\CancelPmVisit;
use App\Modules\Maintenance\Actions\CompletePmVisit;
use App\Modules\Maintenance\Actions\OpenTicketFromPmItem;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SearchPmVisits;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\UpdatePmVisit;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Http\Requests\RecordPmItemRequest;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketLabels;
use App\Modules\Service\Models\Ticket;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PmVisitController extends Controller
{
    public function __construct(
        private ListCustomers $listCustomers,
        private UserNames $userNames,
        private UsersWithPermission $usersWithPermission,
        private Modules $modules,
    ) {}

    public function index(Request $request, SearchPmVisits $search): Response
    {
        Gate::authorize('viewAny', PmVisit::class);

        $filters = SearchPmVisits::filtersFrom($request);
        $visits = $search->handle($request->user(), $filters)->paginate(20)->withQueryString();
        $customers = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');
        $assignees = $this->userNames->handle($visits->pluck('assignee_id')->all());

        return Inertia::render('Maintenance/Visits/Index', [
            'visits' => $visits->through(fn (PmVisit $visit) => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'items_count', 'checked_count', 'issue_count']),
                'plan' => $visit->plan?->title,
                'customer' => $customers[$visit->customer_id] ?? null,
                'assignee' => $assignees[$visit->assignee_id] ?? null,
                'due_on' => $visit->due_on->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'overdue' => $visit->isOverdue(),
            ]),
            'filters' => $filters,
            'statuses' => PmVisit::STATUSES,
            'customers' => $this->listCustomers->handle(),
            'assignees' => $this->assignees(),
        ]);
    }

    public function show(Request $request, PmVisit $visit, AssetDetails $assetDetails, ContractDetails $contractDetails, TicketLabels $ticketLabels): Response
    {
        Gate::authorize('view', $visit);

        $user = $request->user();
        $items = $visit->items()->orderBy('id')->get();
        $assets = $assetDetails->handle($items->pluck('asset_id')->all());
        $tickets = $ticketLabels->handle($items->pluck('ticket_id')->all());
        $names = $this->userNames->handle([$visit->assignee_id, ...$items->pluck('checked_by')->all()]);
        $contract = $contractDetails->handle([$visit->contract_id])[$visit->contract_id] ?? null;
        $canPerform = $user->can('perform', $visit);
        $canUpdate = $user->can('update', $visit);

        return Inertia::render('Maintenance/Visits/Show', [
            'visit' => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'summary', 'assignee_id']),
                'plan' => $visit->plan ? ['id' => $visit->plan->id, 'title' => $visit->plan->title] : null,
                'customer' => collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id)['name'] ?? null,
                'contract' => $contract ? [...collect($contract)->only(['id', 'contract_no', 'title'])->all(), 'can_view' => $user->can('contract.view')] : null,
                // Before the round starts: how many assets it will cover.
                'contract_assets_count' => $contract ? count($contract['asset_ids']) : 0,
                'assignee' => $names[$visit->assignee_id] ?? null,
                'period_starts_on' => $visit->period_starts_on->toDateString(),
                'due_on' => $visit->due_on->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'overdue' => $visit->isOverdue(),
                ...collect(['started_at', 'completed_at', 'cancelled_at'])->mapWithKeys(fn ($field) => [$field => $visit->{$field}?->toIso8601String()]),
            ],
            'items' => $items->map(fn (PmVisitItem $item) => [
                ...$item->only(['id', 'result', 'answers', 'note', 'checklist']),
                'asset' => isset($assets[$item->asset_id])
                    ? [...collect($assets[$item->asset_id])->only(['ulid', 'asset_code', 'name'])->all(), 'can_view' => $user->can('asset.view')]
                    : null,
                'ticket' => $tickets[$item->ticket_id] ?? null,
                'checked_by' => $names[$item->checked_by] ?? null,
                'checked_at' => $item->checked_at?->toIso8601String(),
            ]),
            'assignees' => $canUpdate && $visit->isOpen() ? $this->assignees() : null,
            'priorities' => Ticket::PRIORITIES,
            'can' => [
                'update' => $canUpdate && $visit->isOpen(),
                'start' => $canPerform && $visit->status === PmVisit::STATUS_SCHEDULED,
                'perform' => $canPerform && $visit->status === PmVisit::STATUS_IN_PROGRESS,
                'cancel' => $user->can('cancel', $visit) && $visit->isOpen(),
                // Tickets from PM need the Service module; opening one is part of performing PM.
                'openTicket' => $canPerform && $this->modules->enabled('service'),
            ],
        ]);
    }

    /**
     * Appointment date and technician.
     */
    public function update(Request $request, PmVisit $visit, UpdatePmVisit $updateVisit): RedirectResponse
    {
        Gate::authorize('update', $visit);

        $data = $request->validate([
            'scheduled_on' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('maintenance.fields.assignee_id')]));
                }
            }],
        ], [], __('maintenance.fields'));

        $updateVisit->handle($visit, $data);

        return back()->with('success', __('maintenance.visits.updated'));
    }

    public function start(Request $request, PmVisit $visit, StartPmVisit $startVisit): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $startVisit->handle($visit, $request->user());

        return back()->with('success', __('maintenance.visits.started'));
    }

    public function recordItem(RecordPmItemRequest $request, PmVisit $visit, PmVisitItem $item, RecordPmItem $recordItem): RedirectResponse
    {
        $recordItem->handle($item, $request->user(), $request->validated());

        return back()->with('success', __('maintenance.visits.item_saved'));
    }

    public function openTicket(Request $request, PmVisit $visit, PmVisitItem $item, OpenTicketFromPmItem $openTicket): RedirectResponse
    {
        Gate::authorize('perform', $visit);
        abort_unless($this->modules->enabled('service'), 404);

        $data = $request->validate(['priority' => ['required', Rule::in(Ticket::PRIORITIES)]], [], __('maintenance.fields'));
        $ticket = $openTicket->handle($item, $request->user(), $data['priority']);

        return back()->with('success', __('maintenance.visits.ticket_opened', ['no' => $ticket->ticket_no]));
    }

    public function complete(Request $request, PmVisit $visit, CompletePmVisit $completeVisit): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $data = $request->validate(['summary' => ['nullable', 'string', 'max:5000']], [], __('maintenance.fields'));
        $completeVisit->handle($visit, $data['summary'] ?? null);

        return back()->with('success', __('maintenance.visits.completed'));
    }

    public function cancel(Request $request, PmVisit $visit, CancelPmVisit $cancelVisit): RedirectResponse
    {
        Gate::authorize('cancel', $visit);

        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']], [], __('maintenance.fields'));
        $cancelVisit->handle($visit, $data['reason']);

        return back()->with('success', __('maintenance.visits.cancelled'));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function assignees(): array
    {
        return $this->usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)
            ->map(fn (User $user) => $user->only(['id', 'name']))->values()->all();
    }
}
