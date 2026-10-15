<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\OpenTicketFromPmItem;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SearchPmVisits;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Actions\UpdatePmVisit;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Http\Requests\RecordPmItemRequest;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\PmWorkflow;
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
        $staff = $request->user()->customer_id === null;
        $visits = $search->handle($request->user(), $filters)->paginate(20)->withQueryString();
        $customers = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');
        $assignees = $this->userNames->handle($visits->pluck('assignee_id')->all());

        return Inertia::render('Maintenance/Visits/Index', [
            'visits' => $visits->through(fn (PmVisit $visit) => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'items_count', 'checked_count', 'issue_count']),
                'plan' => $visit->displayTitle(),
                'customer' => $customers[$visit->customer_id] ?? null,
                'assignee' => $assignees[$visit->assignee_id] ?? null,
                'due_on' => $visit->due_on->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'overdue' => $visit->isOverdue(),
            ]),
            'filters' => $filters,
            'statuses' => PmVisit::STATUSES,
            // A customer account does not filter by customer or technician.
            'customers' => $staff ? $this->listCustomers->handle() : [],
            'assignees' => $staff ? $this->assignees() : [],
            'can' => ['create' => $staff && $request->user()->can('create', PmVisit::class)],
        ]);
    }

    public function show(Request $request, PmVisit $visit, AssetDetails $assetDetails, ContractDetails $contractDetails, TicketLabels $ticketLabels): Response
    {
        Gate::authorize('view', $visit);

        $user = $request->user();
        $items = $visit->items()->with('media')->orderBy('id')->get();
        $assets = $assetDetails->handle($items->pluck('asset_id')->all());
        $tickets = $ticketLabels->handle($items->pluck('ticket_id')->all());
        $names = $this->userNames->handle([$visit->assignee_id, ...$items->pluck('checked_by')->all()]);
        $contract = $contractDetails->handle([$visit->contract_id])[$visit->contract_id] ?? null;
        $canPerform = $user->can('perform', $visit);
        $canUpdate = $user->can('update', $visit);
        $canEditItems = $canUpdate && $visit->isOpen();
        $canShare = $user->customer_id === null && $user->can('share', $visit);

        $staff = $user->customer_id === null;
        $settings = PmSettings::of();
        // The moves this user may make now: allowed by the status, and by their permission.
        $moves = array_values(array_filter(PmWorkflow::available($visit), fn (string $action) => $user->can(PmWorkflow::ACTIONS[$action]['ability'], $visit)));

        // A customer account sees the schedule and the equipment, never the work in the making: results,
        // notes, photos and the reasons work was sent back reach the customer only as the published
        // report (its PDF, PmReportVersionFor).
        $hideWork = ! $staff;

        return Inertia::render('Maintenance/Visits/Show', [
            'visit' => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'assignee_id', 'revision_count', 'current_version', 'published_version']),
                'summary' => $hideWork && $visit->status !== PmVisit::STATUS_CANCELLED ? null : $visit->summary,
                'revision_note' => $hideWork ? null : $visit->revision_note,
                'title' => $visit->displayTitle(),
                ...collect(['reviewed_at', 'approved_at', 'published_at', 'closed_at'])->mapWithKeys(fn ($field) => [$field => $visit->{$field}?->toIso8601String()]),
                'signatures' => collect([PmVisit::TECHNICIAN_SIGNATURE, PmVisit::CUSTOMER_SIGNATURE])->mapWithKeys(fn (string $collection) => [
                    $collection => ($media = $visit->getFirstMedia($collection))
                        ? ['signer' => $media->getCustomProperty('signer'), 'position' => $media->getCustomProperty('position'), 'signed_at' => $media->getCustomProperty('signed_at')]
                        : null,
                ]),
                'plan' => $visit->plan ? ['id' => $visit->plan->id, 'title' => $visit->plan->title, 'can_view' => $user->can('view', $visit->plan)] : null,
                'customer' => collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id)['name'] ?? null,
                'contract' => $contract ? [...collect($contract)->only(['id', 'contract_no', 'title'])->all(), 'can_view' => $user->can('contracts.view')] : null,
                // Before the round starts: how many assets it will cover.
                'contract_assets_count' => $contract ? count($contract['asset_ids']) : 0,
                'assignee' => $names[$visit->assignee_id] ?? null,
                'period_starts_on' => $visit->period_starts_on?->toDateString(),
                'due_on' => $visit->due_on->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'overdue' => $visit->isOverdue(),
                ...collect(['started_at', 'completed_at', 'cancelled_at'])->mapWithKeys(fn ($field) => [$field => $visit->{$field}?->toIso8601String()]),
            ],
            'items' => $items->map(fn (PmVisitItem $item) => $hideWork ? [
                ...$item->only(['id', 'checklist']),
                'result' => PmVisitItem::RESULT_PENDING, 'answers' => (object) [], 'remarks' => (object) [], 'note' => null, 'photos' => [],
                'asset' => isset($assets[$item->asset_id]) ? [...collect($assets[$item->asset_id])->only(['ulid', 'asset_code', 'name'])->all(), 'can_view' => $user->can('assets.view')] : null,
                'ticket' => null, 'checked_by' => null, 'checked_at' => null, 'removable' => false,
            ] : [
                ...$item->only(['id', 'result', 'answers', 'note', 'checklist']),
                'asset' => isset($assets[$item->asset_id])
                    ? [...collect($assets[$item->asset_id])->only(['ulid', 'asset_code', 'name'])->all(), 'can_view' => $user->can('assets.view')]
                    : null,
                'ticket' => $tickets[$item->ticket_id] ?? null,
                'remarks' => $item->remarks ?? (object) [],
                'photos' => $item->getMedia(PmVisitItem::PHOTOS)->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'question' => $media->getCustomProperty('question'),
                    'url' => route('maintenance.visits.items.photos.show', [$visit, $item, $media->id]),
                ])->values(),
                'checked_by' => $names[$item->checked_by] ?? null,
                'checked_at' => $item->checked_at?->toIso8601String(),
                // Equipment with nothing recorded may still be taken off a work order that is still to do.
                'removable' => $canEditItems && $item->result === PmVisitItem::RESULT_PENDING && $item->ticket_id === null && ! $item->hasMedia(PmVisitItem::PHOTOS),
            ]),
            // ?asset_search= : equipment of the customer that may still be put on the work order.
            'assetSearch' => $request->string('asset_search')->trim()->value(),
            'candidates' => fn () => $canEditItems && $request->filled('asset_search') && $this->modules->enabled('asset')
                ? app(AssetSummaries::class)->handle($user, [
                    'customer_id' => $visit->customer_id,
                    'exclude' => $items->pluck('asset_id')->all(),
                    'search' => $request->string('asset_search')->value(),
                    'limit' => 20,
                ])
                : [],
            'assignees' => $canUpdate && $visit->isOpen() ? $this->assignees() : null,
            'priorities' => Ticket::PRIORITIES,
            'moves' => array_values(array_diff($moves, ['start', 'cancel'])),
            'needsNote' => PmWorkflow::NEEDS_NOTE,
            'signing' => [
                'technician' => $settings->requireTechnicianSignature,
                'customer' => $settings->requireCustomerSignature,
                'review_step' => $settings->reviewStep,
            ],
            // The work order's lasting history (staff only: it names the people who worked on it).
            'events' => $staff ? $visit->events()->latest('id')->limit(100)->get()->map(fn (PmVisitEvent $event) => [
                ...$event->only(['id', 'type', 'from_status', 'to_status', 'note', 'data', 'user_name']),
                'at' => $event->created_at->toIso8601String(),
            ]) : [],
            // Customers' links to the published report (who may share them only; the link itself is never shown again).
            'shares' => $canShare ? $visit->shares()->with('version:id,version')->latest('id')->get()->map(fn (PmReportShare $share) => [
                ...$share->only(['id', 'recipient_name', 'recipient_email', 'allow_download', 'view_count', 'created_by_name', 'revoked_by_name']),
                'version' => $share->version?->version,
                'active' => $share->isActive(),
                'expires_at' => $share->expires_at->toIso8601String(),
                'revoked_at' => $share->revoked_at?->toIso8601String(),
                'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
                'created_at' => $share->created_at->toIso8601String(),
            ]) : [],
            'shareDays' => $settings->shareDays,
            'maxShareDays' => PmReportShare::MAX_DAYS,
            // A customer account: only the version given to it.
            'versions' => $visit->versions()->when($hideWork, fn ($q) => $q->where('version', $visit->published_version))
                ->orderByDesc('version')->get(['id', 'version', 'approved_by_name', 'created_at'])
                ->map(fn ($version) => [...$version->only(['id', 'version', 'approved_by_name']), 'at' => $version->created_at->toIso8601String()]),
            'can' => [
                'update' => $canUpdate && $visit->isOpen(),
                'start' => in_array('start', $moves, true),
                'perform' => $canPerform && $visit->isWorking(),
                'cancel' => in_array('cancel', $moves, true),
                // Tickets from PM need the Service module; opening one is part of performing PM.
                'openTicket' => $canPerform && $this->modules->enabled('service'),
                'editItems' => $canEditItems,
                'share' => $canShare && $visit->published_version > 0 && $visit->status !== PmVisit::STATUS_CANCELLED,
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

    /**
     * The technician hands the work in, with the summary and the signatures drawn on the screen.
     */
    public function submit(Request $request, PmVisit $visit, SubmitPmVisit $submitVisit): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $signature = ['nullable', 'string', 'starts_with:data:image/png;base64,', 'max:400000'];
        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
            'technician_signature' => $signature,
            'customer_signature' => $signature,
            'customer_signer_name' => ['nullable', 'string', 'max:255'],
            'customer_signer_position' => ['nullable', 'string', 'max:255'],
        ], [], __('maintenance.fields'));
        $submitVisit->handle($visit, $request->user(), $data);

        return back()->with('success', __('maintenance.visits.submitted'));
    }

    /**
     * Every other move of the workflow (PmWorkflow): schedule, review, return, approve, publish,
     * close, reopen, cancel. Who may make it is the move's policy ability.
     */
    public function move(Request $request, PmVisit $visit, MovePmVisit $moveVisit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_diff(array_keys(PmWorkflow::ACTIONS), array_keys(PmWorkflow::OWN_ACTION)))],
            'note' => ['nullable', 'string', 'max:5000'],
        ], [], __('maintenance.fields'));
        Gate::authorize(PmWorkflow::ACTIONS[$data['action']]['ability'], $visit);

        $moveVisit->handle($visit, $data['action'], $request->user(), $data['note'] ?? null);

        return back()->with('success', __('maintenance.visits.moved.'.$data['action']));
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
