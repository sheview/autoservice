<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetDevices;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Asset\Actions\IpChoices;
use App\Modules\Asset\Actions\IpLabels;
use App\Modules\Asset\Actions\IpOfAsset;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Support\Attachments;
use App\Modules\Identity\Actions\ContactPeople;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\IssuableParts;
use App\Modules\Inventory\Actions\TicketParts;
use App\Modules\Labeling\Actions\QrSvg;
use App\Modules\Platform\CrossTenant\ForwardedTickets;
use App\Modules\Platform\CrossTenant\SharedRequests;
use App\Modules\Platform\CrossTenant\ShareGateway;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Actions\SearchTickets;
use App\Modules\Service\Actions\TicketCustomerIds;
use App\Modules\Service\Actions\UpdateTicket;
use App\Modules\Service\Http\Requests\OpenTicketRequest;
use App\Modules\Service\Http\Requests\UpdateTicketRequest;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSlaState;
use App\Modules\Service\Support\TicketWorkflow;
use App\Modules\Survey\Actions\SurveyOfTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    /** Users who can be given tickets: whoever may work on them. */
    public const ASSIGNABLE_PERMISSION = 'tickets.update';

    public function __construct(
        private Modules $modules,
        private ListCustomers $listCustomers,
        private UserNames $userNames,
    ) {}

    public function index(Request $request, SearchTickets $search, TicketCustomerIds $ticketCustomerIds): Response
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
                // For a datetime-local input, in the app's time zone.
                'appointment_at' => $ticket->appointment_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i'),
                'created_at' => $ticket->created_at->toIso8601String(),
            ]),
            'filters' => $filters,
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            // Scope "own": only the customers of the user's own tickets in the filter.
            'customers' => DataScope::of($user, 'tickets.view') === PermissionCatalog::SCOPE_OWN
                ? array_values(array_filter($this->customers(), fn (array $c) => in_array($c['id'], $ticketCustomerIds->handle($user), true)))
                : $this->customers(),
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
        ContactPeople $contactPeople,
    ): Response {
        Gate::authorize('create', Ticket::class);

        $user = $request->user();
        $asset = null;
        if ($request->filled('asset')) {
            $found = array_values($assetDetails->handle([$request->string('asset')->value()], byUlid: true))[0] ?? null;
            $asset = $found ? ($assetSummaries->handle($user, ['ids' => [$found['id']]])[0] ?? null) : null;
        }

        // A customer account always opens tickets for its own customer.
        $customerId = $user->customer_id ?? ($request->integer('customer_id') ?: $asset['customer_id'] ?? null);
        $assetId = $request->integer('asset_id') ?: $asset['id'] ?? null;
        $assetSearch = $request->string('asset_search')->trim()->value();

        return Inertia::render('Service/Tickets/Create', [
            'preset' => ['asset' => $asset, 'customer_id' => $customerId],
            // Customer accounts do not pick customer, contract, source or assignee.
            'customerAccount' => $user->customer_id !== null,
            'customers' => $this->customers(),
            'assetOptions' => fn () => $assetSearch === '' ? [] : $assetSummaries->handle($user, array_filter([
                'customer_id' => $customerId,
                'search' => $assetSearch,
                'limit' => 20,
            ], fn ($value) => $value !== null)),
            // A customer account does not choose the contract (OpenTicket picks the covering one).
            'contracts' => fn () => $this->modules->enabled('contract') && $user->customer_id === null
                ? $coveringContracts->handle($customerId, $assetId)
                : [],
            'assignees' => $user->can('tickets.assign')
                ? $usersWithPermission->handle(self::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values()
                : [],
            // Who may be reporting: the customer's accounts, or the tenant's own staff without a customer.
            'contactPeople' => fn () => $contactPeople->handle($customerId),
            'priorities' => Ticket::PRIORITIES,
            'sources' => Ticket::SOURCES,
        ]);
    }

    public function store(OpenTicketRequest $request, OpenTicket $openTicket, AddAttachments $addAttachments): RedirectResponse
    {
        $ticket = $openTicket->handle($request->user(), $request->ticketData());
        $addAttachments->handle($ticket, $request->attachments());

        return redirect()->route('service.tickets.show', $ticket)->with('success', __('service.tickets.created', ['no' => $ticket->ticket_no]));
    }

    public function show(
        Request $request,
        Ticket $ticket,
        AssetDetails $assetDetails,
        AssetDevices $assetDevices,
        ContractLabels $contractLabels,
        UsersWithPermission $usersWithPermission,
        TicketParts $ticketParts,
        IssuableParts $issuableParts,
        SurveyOfTicket $surveyOfTicket,
        QrSvg $qrSvg,
        IpLabels $ipLabels,
        IpOfAsset $ipOfAsset,
        IpChoices $ipChoices,
        SharedRequests $sharedRequests,
        ShareGateway $gateway,
        ForwardedTickets $forwardedTickets,
    ): Response {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $canIssueParts = TicketPartController::allows($user, $ticket) && in_array($ticket->status, TicketPartController::STATUSES, true);
        $names = $this->userNames->handle([$ticket->assignee_id, $ticket->reported_by]);
        $asset = $ticket->asset_id ? ($assetDetails->handle([$ticket->asset_id])[$ticket->asset_id] ?? null) : null;
        $device = $asset ? ($assetDevices->handle([$asset['id']])[$asset['id']] ?? null) : null;
        $contract = $ticket->contract_id ? ($contractLabels->handle([$ticket->contract_id])[$ticket->contract_id] ?? null) : null;
        $canAssign = $user->can('assign', $ticket);

        return Inertia::render('Service/Tickets/Show', [
            'ticket' => [
                ...$ticket->only([
                    'ulid', 'ticket_no', 'title', 'description', 'status', 'priority', 'source',
                    'contact_name', 'contact_phone', 'service_window', 'response_minutes', 'resolve_minutes', 'hold_minutes',
                ]),
                'customer' => collect($this->customers(withTrashed: true))->firstWhere('id', $ticket->customer_id)['name'] ?? null,
                'asset' => $asset ? [...$asset, 'can_view' => $user->can('assets.view')] : null,
                'device' => [
                    ...$ticket->only(['device_name', 'device_brand', 'device_model', 'device_serial', 'device_serial_unknown', 'device_location', 'device_ip']),
                    'property_no' => $device['property_no'] ?? null,
                ],
                // The repair report is for staff; extra_cost in baht.
                'report' => $user->customer_id === null ? [
                    'cause' => $ticket->cause,
                    'extra_cost' => Money::toBaht($ticket->extra_cost),
                    'approver_name' => $ticket->approver_name,
                ] : null,
                'warranty' => [
                    'status' => $ticket->warranty_status,
                    'expires_on' => $ticket->warranty_expires_on?->toDateString(),
                    'checked_by' => $ticket->warranty_checked_by_name,
                    'checked_at' => $ticket->warranty_checked_at?->toIso8601String(),
                    // A registered asset: what its warranty date says today, for staff to confirm.
                    'asset_expires_on' => $device['warranty_expires_at'] ?? null,
                ],
                'contract' => $contract ? [...$contract, 'can_view' => $user->can('contracts.view')] : null,
                'branch' => $ticket->branch?->name,
                'assignee_id' => $ticket->assignee_id,
                'assignee' => $names[$ticket->assignee_id] ?? null,
                'reporter' => $names[$ticket->reported_by] ?? null,
                'sla' => TicketSlaState::of($ticket),
                // For a datetime-local input, in the app's time zone.
                'appointment_at' => $ticket->appointment_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i'),
                ...collect(['response_due_at', 'resolve_due_at', 'responded_at', 'on_hold_since', 'resolved_at', 'closed_at', 'cancelled_at', 'created_at'])
                    ->mapWithKeys(fn ($field) => [$field => $ticket->{$field}?->toIso8601String()]),
            ],
            // Internal notes are for staff only.
            'events' => $ticket->events()
                ->when($user->customer_id !== null, fn ($q) => $q->where('is_internal', false))
                ->orderBy('id')->get()->map(fn (TicketEvent $event) => [
                    ...$event->only(['id', 'type', 'from_status', 'to_status', 'body', 'is_internal', 'user_name']),
                    'at' => $event->created_at->toIso8601String(),
                ]),
            // Workflow buttons the user may press now.
            'actions' => collect(TicketWorkflow::ACTIONS)
                ->filter(fn (array $action, string $name) => TicketWorkflow::allows($ticket, $name) && $user->can($action['ability'], $ticket))
                ->keys()
                ->values(),
            'needsComment' => TicketWorkflow::NEEDS_COMMENT,
            // The IP address the job is about (IP management, staff only); "choices" fill the picker (ip_search).
            'ip' => $user->customer_id === null && $this->modules->enabled('asset') && $user->can('ip-check.view') ? [
                'current' => $ticket->ip_address_id ? ($ipLabels->handle([$ticket->ip_address_id])[$ticket->ip_address_id] ?? null) : null,
                'suggested' => ! $ticket->ip_address_id && $ticket->asset_id ? $ipOfAsset->handle($ticket->asset_id) : null,
                'can_change' => $user->can('update', $ticket),
            ] : null,
            // Parts and assets asked of other companies for this job (Platform\CrossTenant); staff only.
            'sharedRequests' => $user->customer_id === null ? $sharedRequests->forTicket($ticket->id) : [],
            // The job sent on to other companies, and where it came from if another company sent it to us.
            'forwards' => $user->customer_id === null ? [
                'tickets' => $forwardedTickets->forTicket($ticket->id),
                'companies' => $user->can('update', $ticket) ? $gateway->targets($user, 'tickets.forward')->map(fn ($c) => $c->only(['id', 'name']))->values() : [],
                'from' => $forwardedTickets->forwardedFrom($ticket->id),
            ] : null,
            // Where to ask other companies for parts for this job (only when one shares with us).
            'askOthersUrl' => $user->customer_id === null && ($user->can('asset-checkouts.request') || $user->can('asset-checkouts.create'))
                && ($gateway->targets($user, 'parts.request')->isNotEmpty() || $gateway->targets($user, 'assets.request')->isNotEmpty())
                ? route('platform.shared-search', ['ticket' => $ticket->id]) : null,
            'ipChoices' => Inertia::optional(fn () => $ipChoices->handle(
                $request->string('ip_search')->value(),
                $ticket->customer_id ?? 0,
            )),
            'assignees' => $canAssign && in_array($ticket->status, Ticket::OPEN_STATUSES, true)
                ? $usersWithPermission->handle(self::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values()
                : null,
            // Spare parts used on the job (Inventory module); null = the user does not see stock.
            'parts' => $this->modules->enabled('inventory') && $user->can('parts.view') ? [
                'items' => $ticketParts->handle($ticket->id),
                'options' => $canIssueParts ? $issuableParts->handle() : [],
                'types' => IssuableParts::TYPES,
                'canIssue' => $canIssueParts,
                // Loans and spares may come back after the job is closed.
                'canReturn' => TicketPartController::allows($user, $ticket),
            ] : null,
            'survey' => $this->survey($ticket, $user, $surveyOfTicket, $qrSvg),
            'attachments' => $this->attachments($ticket),
            'can' => [
                'update' => $user->can('update', $ticket) && in_array($ticket->status, Ticket::OPEN_STATUSES, true),
                'comment' => $user->can('comment', $ticket),
                'deleteAttachments' => $user->can('update', $ticket),
                'internalNotes' => $user->customer_id === null,
                'checkWarranty' => TicketActionController::canCheckWarranty($user, $ticket),
                'report' => TicketActionController::canReport($user, $ticket),
            ],
        ]);
    }

    /**
     * The satisfaction survey of a closed ticket (Survey module). Staff with surveys.view see the
     * answer, and while there is none the public link (with its QR code) to send to the customer;
     * whoever may answer gets the form. Null = nothing to show to this user.
     *
     * @return array<string, mixed>|null
     */
    private function survey(Ticket $ticket, User $user, SurveyOfTicket $surveyOfTicket, QrSvg $qrSvg): ?array
    {
        $canView = TicketSurveyController::allowsView($user, $ticket);
        $canAnswer = TicketSurveyController::allows($user, $ticket);
        $canPaper = TicketSurveyController::allowsPaper($user, $ticket);

        if (! $this->modules->enabled('survey') || ! ($canView || $canAnswer || $canPaper)) {
            return null;
        }

        $survey = $surveyOfTicket->handle($ticket->id);
        if ($survey === null) {
            return null;
        }

        // Staff send the link; a customer account answers on the page instead, and the technician
        // who did the job never gets the link to rate their own work.
        $shareLink = $canView && ! $survey['answered'] && $user->customer_id === null
            && (int) $ticket->assignee_id !== (int) $user->id;

        return [
            ...collect($survey)->except('url')->all(),
            'canAnswer' => $canAnswer && ! $survey['answered'],
            // The score ticked on the printed job sheet, keyed in by staff.
            'canPaper' => $canPaper && ! $survey['answered'],
            'url' => $shareLink ? $survey['url'] : null,
            'qr' => $shareLink ? $qrSvg->handle($survey['url']) : null,
        ];
    }

    public function edit(Ticket $ticket): Response
    {
        Gate::authorize('update', $ticket);
        abort_unless(in_array($ticket->status, Ticket::OPEN_STATUSES, true), 403);

        return Inertia::render('Service/Tickets/Edit', [
            'ticket' => $ticket->only(['ulid', 'ticket_no', 'title', 'description', 'priority', 'source', 'contact_name', 'contact_phone']),
            'attachments' => $this->attachments($ticket),
            'outOfContract' => $ticket->contract_id === null,
            'priorities' => Ticket::PRIORITIES,
            'sources' => Ticket::SOURCES,
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $updateTicket, AddAttachments $addAttachments): RedirectResponse
    {
        $updateTicket->handle($ticket, $request->ticketData(), $request->user());
        $addAttachments->handle($ticket, $request->attachments());

        return redirect()->route('service.tickets.show', $ticket)->with('success', __('service.tickets.updated'));
    }

    /**
     * @return list<array{id: int, name: string, size: int, uploaded_at: string|null, url: string}>
     */
    private function attachments(Ticket $ticket): array
    {
        return Attachments::list($ticket, $ticket->attachmentCollection(), fn (int $id) => route('service.tickets.attachments.show', [$ticket, $id]));
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function customers(bool $withTrashed = false): array
    {
        $customers = $this->modules->enabled('contract') ? $this->listCustomers->handle($withTrashed) : [];

        // A customer account only ever learns about its own customer.
        $own = request()->user()?->customer_id;

        return $own === null ? $customers : array_values(array_filter($customers, fn (array $c) => $c['id'] === $own));
    }
}
