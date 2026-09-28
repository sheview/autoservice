<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Actions\SearchTickets;
use App\Modules\Service\Actions\UpdateTicket;
use App\Modules\Service\Http\Requests\OpenTicketRequest;
use App\Modules\Service\Http\Requests\UpdateTicketRequest;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSlaState;
use App\Modules\Service\Support\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    /** Users who can be given tickets: whoever may work on them. */
    public const ASSIGNABLE_PERMISSION = 'ticket.update';

    public function __construct(
        private Modules $modules,
        private ListCustomers $listCustomers,
        private UserNames $userNames,
    ) {}

    public function index(Request $request, SearchTickets $search): Response
    {
        Gate::authorize('viewAny', Ticket::class);

        $filters = SearchTickets::filtersFrom($request);
        $user = $request->user();

        $tickets = $search->handle($user, $filters)->paginate(20)->withQueryString();
        $customerNames = collect($this->customers(withTrashed: true))->pluck('name', 'id');
        $assigneeNames = $this->userNames->handle($tickets->pluck('assignee_id')->all());

        return Inertia::render('Service/Tickets/Index', [
            'tickets' => $tickets->through(fn (Ticket $ticket) => [
                ...$ticket->only(['ulid', 'ticket_no', 'title', 'status', 'priority']),
                'customer' => $customerNames[$ticket->customer_id] ?? null,
                'assignee' => $assigneeNames[$ticket->assignee_id] ?? null,
                'out_of_contract' => $ticket->contract_id === null,
                'resolve_due_at' => $ticket->resolve_due_at?->toIso8601String(),
                'sla' => TicketSlaState::of($ticket),
                'created_at' => $ticket->created_at->toIso8601String(),
            ]),
            'filters' => $filters,
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            'customers' => $this->customers(),
            'dueSoonHours' => SearchTickets::DUE_SOON_HOURS,
            'can' => ['create' => $user->can('create', Ticket::class)],
        ]);
    }

    /**
     * ?asset=ulid opens the form for that asset. Partial reloads fill "assetOptions" (customer_id,
     * asset_search) and "contracts" (customer_id, asset_id) while the form is being filled in.
     */
    public function create(
        Request $request,
        AssetDetails $assetDetails,
        AssetSummaries $assetSummaries,
        CoveringContracts $coveringContracts,
        UsersWithPermission $usersWithPermission,
    ): Response {
        Gate::authorize('create', Ticket::class);

        $user = $request->user();
        $asset = null;
        if ($request->filled('asset')) {
            $found = array_values($assetDetails->handle([$request->string('asset')->value()], byUlid: true))[0] ?? null;
            $asset = $found ? ($assetSummaries->handle($user, ['ids' => [$found['id']]])[0] ?? null) : null;
        }

        $customerId = $request->integer('customer_id') ?: $asset['customer_id'] ?? null;
        $assetId = $request->integer('asset_id') ?: $asset['id'] ?? null;
        $assetSearch = $request->string('asset_search')->trim()->value();

        return Inertia::render('Service/Tickets/Create', [
            'preset' => ['asset' => $asset, 'customer_id' => $customerId],
            'customers' => $this->customers(),
            'assetOptions' => fn () => $assetSearch === '' ? [] : $assetSummaries->handle($user, array_filter([
                'customer_id' => $customerId,
                'search' => $assetSearch,
                'limit' => 20,
            ], fn ($value) => $value !== null)),
            'contracts' => fn () => $this->modules->enabled('contract') ? $coveringContracts->handle($customerId, $assetId) : [],
            'assignees' => $user->can('ticket.assign')
                ? $usersWithPermission->handle(self::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values()
                : [],
            'priorities' => Ticket::PRIORITIES,
            'sources' => Ticket::SOURCES,
        ]);
    }

    public function store(OpenTicketRequest $request, OpenTicket $openTicket): RedirectResponse
    {
        $ticket = $openTicket->handle($request->user(), $request->validated());

        return redirect()->route('service.tickets.show', $ticket)->with('success', __('service.tickets.created', ['no' => $ticket->ticket_no]));
    }

    public function show(
        Request $request,
        Ticket $ticket,
        AssetDetails $assetDetails,
        ContractLabels $contractLabels,
        UsersWithPermission $usersWithPermission,
    ): Response {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $names = $this->userNames->handle([$ticket->assignee_id, $ticket->reported_by]);
        $asset = $ticket->asset_id ? ($assetDetails->handle([$ticket->asset_id])[$ticket->asset_id] ?? null) : null;
        $contract = $ticket->contract_id ? ($contractLabels->handle([$ticket->contract_id])[$ticket->contract_id] ?? null) : null;
        $canAssign = $user->can('assign', $ticket);

        return Inertia::render('Service/Tickets/Show', [
            'ticket' => [
                ...$ticket->only([
                    'ulid', 'ticket_no', 'title', 'description', 'status', 'priority', 'source',
                    'contact_name', 'contact_phone', 'service_window', 'response_minutes', 'resolve_minutes', 'hold_minutes',
                ]),
                'customer' => collect($this->customers(withTrashed: true))->firstWhere('id', $ticket->customer_id)['name'] ?? null,
                'asset' => $asset ? [...$asset, 'can_view' => $user->can('asset.view')] : null,
                'contract' => $contract ? [...$contract, 'can_view' => $user->can('contract.view')] : null,
                'branch' => $ticket->branch?->name,
                'assignee_id' => $ticket->assignee_id,
                'assignee' => $names[$ticket->assignee_id] ?? null,
                'reporter' => $names[$ticket->reported_by] ?? null,
                'sla' => TicketSlaState::of($ticket),
                ...collect(['response_due_at', 'resolve_due_at', 'responded_at', 'on_hold_since', 'resolved_at', 'closed_at', 'cancelled_at', 'created_at'])
                    ->mapWithKeys(fn ($field) => [$field => $ticket->{$field}?->toIso8601String()]),
            ],
            'events' => $ticket->events()->orderBy('id')->get()->map(fn (TicketEvent $event) => [
                ...$event->only(['id', 'type', 'from_status', 'to_status', 'body', 'is_internal', 'user_name']),
                'at' => $event->created_at->toIso8601String(),
            ]),
            // Workflow buttons the user may press now.
            'actions' => collect(TicketWorkflow::ACTIONS)
                ->filter(fn (array $action, string $name) => TicketWorkflow::allows($ticket, $name) && $user->can($action['ability'], $ticket))
                ->keys()
                ->values(),
            'needsComment' => TicketWorkflow::NEEDS_COMMENT,
            'assignees' => $canAssign && in_array($ticket->status, Ticket::OPEN_STATUSES, true)
                ? $usersWithPermission->handle(self::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values()
                : null,
            'can' => [
                'update' => $user->can('update', $ticket) && in_array($ticket->status, Ticket::OPEN_STATUSES, true),
                'comment' => $user->can('comment', $ticket),
            ],
        ]);
    }

    public function edit(Ticket $ticket): Response
    {
        Gate::authorize('update', $ticket);
        abort_unless(in_array($ticket->status, Ticket::OPEN_STATUSES, true), 403);

        return Inertia::render('Service/Tickets/Edit', [
            'ticket' => $ticket->only(['ulid', 'ticket_no', 'title', 'description', 'priority', 'source', 'contact_name', 'contact_phone']),
            'outOfContract' => $ticket->contract_id === null,
            'priorities' => Ticket::PRIORITIES,
            'sources' => Ticket::SOURCES,
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $updateTicket): RedirectResponse
    {
        $updateTicket->handle($ticket, $request->validated(), $request->user());

        return redirect()->route('service.tickets.show', $ticket)->with('success', __('service.tickets.updated'));
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function customers(bool $withTrashed = false): array
    {
        return $this->modules->enabled('contract') ? $this->listCustomers->handle($withTrashed) : [];
    }
}
