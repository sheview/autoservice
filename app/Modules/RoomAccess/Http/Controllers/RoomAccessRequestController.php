<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Document\Support\Attachments;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\RoomAccess\Actions\CancelRoomAccessRequest;
use App\Modules\RoomAccess\Actions\DecideRoomAccessRequest;
use App\Modules\RoomAccess\Actions\RoomClashes;
use App\Modules\RoomAccess\Actions\RoomPermitSheet;
use App\Modules\RoomAccess\Actions\RoomRulesForRequest;
use App\Modules\RoomAccess\Actions\SaveRoomAccessRequest;
use App\Modules\RoomAccess\Actions\SearchRoomAccessRequests;
use App\Modules\RoomAccess\Actions\SubmitRoomAccessRequest;
use App\Modules\RoomAccess\Http\Requests\RoomAccessRequestForm;
use App\Modules\RoomAccess\Models\RoomAccessApproval;
use App\Modules\RoomAccess\Models\RoomAccessEvent;
use App\Modules\RoomAccess\Models\RoomAccessItem;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Models\RoomAccessVisit;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\RoomVisitor;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\ApprovalFlow;
use App\Modules\RoomAccess\Support\IdNumber;
use App\Modules\RoomAccess\Support\RoomSchedule;
use App\Modules\RoomAccess\Support\RoomVisit;
use App\Modules\Service\Actions\TicketsForCheckout;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Requests to enter server rooms: the list (own requests or all, by permission), the form (from
 * scratch or copied from an earlier request), the rules of the chosen room for the accept popup,
 * sending it (only with the rules accepted), cancelling, and an entrant's full ID number for
 * whoever holds room-access.view-id (each time in the activity log).
 */
class RoomAccessRequestController extends Controller
{
    public function __construct(private Modules $modules) {}

    public function index(Request $request, SearchRoomAccessRequests $search): Response
    {
        Gate::authorize('viewAny', RoomAccessRequest::class);
        $user = $request->user();
        $filters = SearchRoomAccessRequests::filtersFrom($request);
        $customers = app(CustomerLabelNames::class)->handle();

        return Inertia::render('RoomAccess/Requests/Index', [
            'requests' => $search->handle($user, $filters, $user)->paginate(20)->withQueryString()->through(fn (RoomAccessRequest $r) => [
                ...$r->only(['ulid', 'request_no', 'status', 'requester_name', 'purpose', 'people_count']),
                'room' => $r->room?->name,
                'customer' => $customers[$r->customer_id] ?? '-',
                'planned_start' => $r->planned_start->toIso8601String(),
                'planned_end' => $r->planned_end->toIso8601String(),
            ]),
            'filters' => $filters,
            'statuses' => RoomAccessRequest::STATUSES,
            'rooms' => $this->roomOptions(),
            // How many wait for this user's decision (the "awaiting" filter).
            'awaiting' => $user->can('room-access.approve') ? $search->handle($user, ['status' => 'awaiting'], $user)->count() : null,
            'can' => ['create' => $user->can('create', RoomAccessRequest::class)],
        ]);
    }

    /** ?copy={ulid}: starts from an earlier request of the user's (room, people, equipment, purpose; never ID numbers). */
    public function create(Request $request): Response
    {
        Gate::authorize('create', RoomAccessRequest::class);
        $copy = null;
        if ($request->filled('copy')) {
            $source = RoomAccessRequest::query()->where('ulid', $request->string('copy'))->with(['people', 'items'])->first();
            if ($source && $request->user()->can('view', $source)) {
                $copy = [
                    ...$this->formRow($source, withIds: false),
                    'planned_start' => null,
                    'planned_end' => null,
                ];
            }
        }

        return Inertia::render('RoomAccess/Requests/Form', ['request' => null, 'copy' => $copy, ...$this->formOptions($request->user())]);
    }

    public function store(RoomAccessRequestForm $form, SaveRoomAccessRequest $save, SubmitRoomAccessRequest $submit): RedirectResponse
    {
        $roomRequest = $save->handle(null, $this->checkedLinks($form), $form->user());

        return $this->afterSave($form, $roomRequest, $submit);
    }

    public function show(Request $request, RoomAccessRequest $roomRequest): Response
    {
        Gate::authorize('view', $roomRequest);
        $user = $request->user();
        $roomRequest->load(['room', 'people', 'items', 'acceptances', 'events', 'approvals', 'visits']);
        $step = $roomRequest->status === RoomAccessRequest::STATUS_PENDING ? ApprovalFlow::current($roomRequest) : null;
        $room = $roomRequest->room;

        return Inertia::render('RoomAccess/Requests/Show', [
            'request' => [
                ...$roomRequest->only(['ulid', 'request_no', 'status', 'requester_name', 'purpose', 'decision_note', 'work_summary']),
                'planned_start' => $roomRequest->planned_start->toIso8601String(),
                'planned_end' => $roomRequest->planned_end->toIso8601String(),
                // A standing request: its days and hours, and each time it was used.
                'schedule' => RoomSchedule::describe($roomRequest) ?: null,
                'visits' => $roomRequest->visits->map(fn (RoomAccessVisit $v) => [
                    ...$v->only(['id', 'entered_by_name', 'exited_by_name']),
                    'entered_at' => $v->entered_at->toIso8601String(),
                    'exited_at' => $v->exited_at?->toIso8601String(),
                ])->values(),
                'submitted_at' => $roomRequest->submitted_at?->toIso8601String(),
                'room' => ['ulid' => $room?->ulid, 'name' => $room?->name, 'location' => $room?->location, 'requires_id_number' => (bool) $room?->requires_id_number],
                'customer' => app(CustomerLabelNames::class)->handle()[$roomRequest->customer_id] ?? '-',
                'ticket' => $this->ticketLabel($user, $roomRequest->ticket_id),
                'contract' => $roomRequest->contract_id && $this->modules->enabled('contract')
                    ? (app(ContractLabels::class)->handle([$roomRequest->contract_id])[$roomRequest->contract_id] ?? null) : null,
                'people' => $roomRequest->people->map(fn (RoomAccessPerson $p) => [
                    ...$p->only(['id', 'name', 'company', 'phone']),
                    'id_number' => IdNumber::mask($p->id_number),
                ])->values(),
                'items' => $roomRequest->items->map(fn (RoomAccessItem $i) => $i->only(['name', 'serial_number', 'quantity', 'direction']))->values(),
                'acceptances' => $roomRequest->acceptances->map(fn (RoomRuleAcceptance $a) => [
                    ...$a->only(['context', 'version', 'accepted_by_name', 'on_behalf_of_team', 'snapshot', 'ip']),
                    'accepted_at' => $a->accepted_at->toIso8601String(),
                ])->values(),
                'events' => $roomRequest->events->map(fn (RoomAccessEvent $e) => [
                    ...$e->only(['id', 'action', 'from_status', 'to_status', 'actor_name', 'note']),
                    'at' => $e->created_at->toIso8601String(),
                ])->values(),
                'id_numbers_purged' => $roomRequest->id_numbers_purged_at !== null,
                'approved_at' => $roomRequest->approved_at?->toIso8601String(),
                'entered_at' => $roomRequest->entered_at?->toIso8601String(),
                'entered_by_name' => $roomRequest->entered_by_name,
                'exited_at' => $roomRequest->exited_at?->toIso8601String(),
                'exited_by_name' => $roomRequest->exited_by_name,
                'items_confirmed_at' => $roomRequest->items_confirmed_at?->toIso8601String(),
                'items_confirmed_by_name' => $roomRequest->items_confirmed_by_name,
                'overstaying' => RoomVisit::overstaying($roomRequest),
                'accept_on_enter' => (bool) $room?->accept_on_enter,
                'approvals' => $roomRequest->approvals->map(fn (RoomAccessApproval $a) => [
                    ...$a->only(['step', 'side', 'decision', 'note', 'actor_name', 'round']),
                    'decided_at' => $a->decided_at->toIso8601String(),
                ])->values(),
                // The step deciding now and who it waits for (a named approver, or anyone who may approve).
                'waiting_for' => $step === null ? null : [
                    'step' => $step['position'],
                    'side' => $step['side'],
                    'name' => $step['approver_user_id'] ? User::query()->whereKey($step['approver_user_id'])->value('name') : null,
                ],
            ],
            // The permit (once approved): its QR link for the counter, PDF and print.
            'permit' => in_array($roomRequest->status, RoomPermitController::PRINTABLE, true) ? (function () use ($roomRequest, $user) {
                $sheet = app(RoomPermitSheet::class)->handle($roomRequest);

                $guard = $roomRequest->room?->guard_link ? RoomAccessToken::query()->where('request_id', $roomRequest->id)
                    ->where('purpose', RoomAccessToken::GUARD)->whereNull('revoked_at')->latest('id')->first() : null;

                return [
                    // The guards' link, for whoever sends it on (the requester and approvers).
                    'guard_link' => $guard && ((int) $roomRequest->requester_id === $user->id || $user->can('room-access.approve'))
                        ? PublicUrl::forTenant(app(TenantContext::class)->tenant(), '/room-guard/'.$guard->token) : null,
                    'guard_enabled' => (bool) $roomRequest->room?->guard_link,
                    'link' => $sheet['link'],
                    'qr' => $sheet['qr'],
                    'valid' => $sheet['valid'],
                    'expires_at' => $sheet['expires_at']?->toIso8601String(),
                    'can_renew' => in_array($roomRequest->status, [RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE], true)
                        && ((int) $roomRequest->requester_id === $user->id || $user->can('room-access.approve')),
                ];
            })() : null,
            // Other bookings of the room at the same time, freezes and holidays (while still to happen).
            'clashes' => in_array($roomRequest->status, [RoomAccessRequest::STATUS_DRAFT, RoomAccessRequest::STATUS_PENDING, RoomAccessRequest::STATUS_APPROVED], true)
                ? app(RoomClashes::class)->handle($roomRequest, $user) : null,
            'attachments' => Attachments::list($roomRequest, $roomRequest->attachmentCollection(),
                fn (int $id) => route('room-access.requests.attachments.show', [$roomRequest, $id])),
            'can' => [
                'edit' => $user->can('update', $roomRequest),
                'cancel' => $user->can('cancel', $roomRequest),
                'viewIds' => $user->can('room-access.view-id') && $roomRequest->id_numbers_purged_at === null,
                'copy' => $user->can('create', RoomAccessRequest::class),
                'decide' => ApprovalFlow::canDecide($user, $roomRequest),
                'own' => (int) $roomRequest->requester_id === $user->id,
                'record' => RoomVisit::canRecord($user, $roomRequest),
                'finish' => $roomRequest->status === RoomAccessRequest::STATUS_EXITED && (int) $roomRequest->requester_id === $user->id,
            ],
        ]);
    }

    public function edit(Request $request, RoomAccessRequest $roomRequest): Response
    {
        Gate::authorize('update', $roomRequest);
        $roomRequest->load(['people', 'items']);

        return Inertia::render('RoomAccess/Requests/Form', [
            'request' => ['ulid' => $roomRequest->ulid, 'request_no' => $roomRequest->request_no, ...$this->formRow($roomRequest, withIds: true)],
            'copy' => null,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(RoomAccessRequestForm $form, RoomAccessRequest $roomRequest, SaveRoomAccessRequest $save, SubmitRoomAccessRequest $submit): RedirectResponse
    {
        $save->handle($roomRequest, $this->checkedLinks($form), $form->user());

        return $this->afterSave($form, $roomRequest, $submit);
    }

    /**
     * Saved as a draft; sent too when asked (with the popup's answer). If it cannot be sent, the
     * draft is kept and the form opens again with why.
     */
    private function afterSave(RoomAccessRequestForm $form, RoomAccessRequest $roomRequest, SubmitRoomAccessRequest $submit): RedirectResponse
    {
        if (! $form->boolean('submit')) {
            return redirect()->route('room-access.requests.show', $roomRequest)->with('success', __('room_access.requests.saved'));
        }

        try {
            $submit->handle($roomRequest, $form->user(), [
                'accept' => $form->boolean('accept'),
                'version_id' => $form->integer('version_id') ?: null,
                'ip' => $form->ip(),
                'user_agent' => $form->userAgent(),
            ]);
        } catch (ValidationException $e) {
            return redirect()->route('room-access.requests.edit', $roomRequest)->withErrors($e->errors())
                ->with('error', __('room_access.requests.saved_not_sent'));
        }

        return redirect()->route('room-access.requests.show', $roomRequest)->with('success', __('room_access.requests.submitted'));
    }

    /** Sending for approval: the accept popup's answer comes with it, and is checked again here. */
    public function submit(Request $request, RoomAccessRequest $roomRequest, SubmitRoomAccessRequest $submit): RedirectResponse
    {
        Gate::authorize('update', $roomRequest);
        $data = $request->validate(['accept' => ['nullable', 'boolean'], 'version_id' => ['nullable', 'integer']]);

        $submit->handle($roomRequest, $request->user(), [
            'accept' => (bool) ($data['accept'] ?? false),
            'version_id' => $data['version_id'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('room-access.requests.show', $roomRequest)->with('success', __('room_access.requests.submitted'));
    }

    public function cancel(Request $request, RoomAccessRequest $roomRequest, CancelRoomAccessRequest $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $roomRequest);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $cancel->handle($roomRequest, $request->user(), $data['reason'] ?? null);

        return back()->with('success', __('room_access.requests.cancelled'));
    }

    /** Approve, turn down (why), or send back for more information (what): the step deciding now. */
    public function decide(Request $request, RoomAccessRequest $roomRequest, DecideRoomAccessRequest $decide): RedirectResponse
    {
        Gate::authorize('view', $roomRequest);
        $data = $request->validate([
            'decision' => ['required', Rule::in(DecideRoomAccessRequest::DECISIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], __('room_access.fields'));

        $decide->handle($roomRequest, $data['decision'], $request->user(), $data['note'] ?? null);

        return back()->with('success', __("room_access.approvals.done_{$data['decision']}"));
    }

    /**
     * The rules of one room for the accept popup (only the room asked about). With ?request= (a
     * draft of the user's being sent again) a version that request accepted already is said so.
     */
    public function rules(Request $request, ServerRoom $room, RoomRulesForRequest $rules): JsonResponse
    {
        Gate::authorize('create', RoomAccessRequest::class);
        abort_unless($room->is_active, 404);

        $draft = $request->filled('request') ? RoomAccessRequest::query()->where('ulid', $request->string('request'))->first() : null;
        if ($draft !== null && ! $request->user()->can('update', $draft)) {
            $draft = null;
        }

        return response()->json($rules->handle($room, $request->user(), $draft));
    }

    /** The full rules document of a room's version, for whoever asks to enter it. */
    public function rulesFile(Request $request, ServerRoom $room, int $version): StreamedResponse
    {
        abort_unless($request->user()->can('create', RoomAccessRequest::class) || $request->user()->can('viewAny', RoomAccessRequest::class), 403);
        $media = RoomRuleVersion::query()->where('server_room_id', $room->id)->findOrFail($version)->getFirstMedia(RoomRuleVersion::FILE);
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }

    /** An entrant's full ID number (room-access.view-id), logged each time it is shown. */
    public function revealId(Request $request, RoomAccessRequest $roomRequest, int $person): JsonResponse
    {
        Gate::authorize('view', $roomRequest);
        abort_unless($request->user()->can('room-access.view-id'), 403);
        $entrant = $roomRequest->people()->findOrFail($person);

        activity()->performedOn($roomRequest)->causedBy($request->user())->event('room_access_id_viewed')
            ->withProperties(['request_no' => $roomRequest->request_no, 'person' => $entrant->name])
            ->log(__('room_access.log.id_viewed', ['no' => $roomRequest->request_no, 'name' => $entrant->name]));

        return response()->json(['id_number' => $entrant->id_number]);
    }

    /**
     * What to look out for at the times on the form (other requests for the room, freeze periods,
     * holidays): warnings shown while filling it in. ?request={ulid} leaves that request out.
     */
    public function clashes(Request $request, RoomClashes $clashes): JsonResponse
    {
        Gate::authorize('create', RoomAccessRequest::class);
        $data = $request->validate([
            'server_room_id' => ['required', 'integer'],
            'planned_start' => ['required', 'date'],
            'planned_end' => ['required', 'date', 'after:planned_start'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.weekdays' => ['required_with:recurrence', 'array', 'min:1'],
            'recurrence.weekdays.*' => ['integer', 'between:1,7'],
            'recurrence.start_time' => ['required_with:recurrence', 'date_format:H:i'],
            'recurrence.end_time' => ['required_with:recurrence', 'date_format:H:i', 'after:recurrence.start_time'],
            'request' => ['nullable', 'string'],
        ]);
        $room = ServerRoom::query()->findOrFail($data['server_room_id']);
        $existing = filled($data['request'] ?? null) ? RoomAccessRequest::query()->where('ulid', $data['request'])->first() : null;
        $probe = $existing ?? new RoomAccessRequest;
        $rule = $data['recurrence'] ?? null;
        $probe->forceFill([
            'server_room_id' => $room->id,
            'planned_start' => $rule ? substr($data['planned_start'], 0, 10).' '.$rule['start_time'] : $data['planned_start'],
            'planned_end' => $rule ? substr($data['planned_end'], 0, 10).' '.$rule['end_time'] : $data['planned_end'],
            'recurrence' => $rule ? ['weekdays' => array_map('intval', $rule['weekdays']), 'start_time' => $rule['start_time'], 'end_time' => $rule['end_time']] : null,
        ]);
        abort_if(CarbonImmutable::parse($probe->planned_start)->diffInDays($probe->planned_end) > RoomSchedule::MAX_DAYS + 1, 422);

        return response()->json($clashes->handle($probe, $request->user()));
    }

    /** Open tickets the user may see, for linking the request (Service module). */
    public function tickets(Request $request): JsonResponse
    {
        Gate::authorize('create', RoomAccessRequest::class);

        return response()->json($this->modules->enabled('service')
            ? array_values(app(TicketsForCheckout::class)->handle($request->user(), null, $request->string('q')->trim()->value())) : []);
    }

    /** @return list<array{id: int, ulid: string, name: string, customer: string, location: string|null, requires_id_number: bool}> */
    private function roomOptions(): array
    {
        $customers = app(CustomerLabelNames::class)->handle();

        return ServerRoom::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (ServerRoom $room) => [
                ...$room->only(['id', 'ulid', 'name', 'location', 'requires_id_number']),
                'customer' => $customers[$room->customer_id] ?? '-',
            ])
            ->sortBy(fn (array $room) => $room['customer'].' '.$room['name'])->values()->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(User $user): array
    {
        return [
            'rooms' => $this->roomOptions(),
            'contracts' => $this->modules->enabled('contract') ? app(ContractOptions::class)->handle() : [],
            // Each contract's period, for a standing request "for the contract period".
            'contractPeriods' => $this->modules->enabled('contract')
                ? collect(app(ContractDetails::class)->handle(array_column(app(ContractOptions::class)->handle(), 'id')))
                    ->map(fn (array $c) => ['starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on']])->all()
                : [],
            // People this requester has taken in before (only theirs; never ID numbers).
            'visitors' => RoomVisitor::query()->where('owner_id', $user->id)->orderByDesc('last_used_at')->limit(200)
                ->get(['name', 'company', 'phone'])->map(fn (RoomVisitor $v) => $v->only(['name', 'company', 'phone']))->values(),
            // Earlier requests to copy from (the user's own).
            'previous' => RoomAccessRequest::query()->where('requester_id', $user->id)->with('room:id,name')->latest('id')->limit(20)->get()
                ->map(fn (RoomAccessRequest $r) => ['ulid' => $r->ulid, 'label' => $r->request_no.' · '.($r->room?->name ?? '-').' · '.$r->planned_start->format('d/m/Y')])->values(),
            'serviceOn' => $this->modules->enabled('service'),
        ];
    }

    /** @return array<string, mixed> */
    private function formRow(RoomAccessRequest $request, bool $withIds): array
    {
        return [
            'server_room_id' => $request->server_room_id,
            'planned_start' => $request->planned_start->format('Y-m-d\TH:i'),
            'planned_end' => $request->planned_end->format('Y-m-d\TH:i'),
            'purpose' => $request->purpose,
            'recurrence' => $request->recurrence,
            'ticket' => $this->ticketLabel(request()->user(), $request->ticket_id),
            'contract_id' => $request->contract_id,
            'people' => $request->people->map(fn (RoomAccessPerson $p) => [
                'id' => $withIds ? $p->id : null,
                ...$p->only(['name', 'company', 'phone']),
                // Never the number itself: a blank field keeps it (SaveRoomAccessRequest).
                'id_number' => '',
                'id_number_masked' => $withIds ? IdNumber::mask($p->id_number) : null,
            ])->values(),
            'items' => $request->items->map(fn (RoomAccessItem $i) => $i->only(['name', 'serial_number', 'quantity', 'direction']))->values(),
        ];
    }

    /** @return array{id: int, ulid: string, ticket_no: string, title: string}|null */
    private function ticketLabel(User $user, ?int $ticketId): ?array
    {
        if ($ticketId === null || ! $this->modules->enabled('service')) {
            return null;
        }
        $ticket = app(TicketsForCheckout::class)->handle($user, [$ticketId])[$ticketId] ?? null;

        return $ticket ? collect($ticket)->only(['id', 'ulid', 'ticket_no', 'title'])->all() : null;
    }

    /**
     * The validated form, with the ticket and contract kept only when the user may see them.
     *
     * @return array<string, mixed>
     */
    private function checkedLinks(RoomAccessRequestForm $form): array
    {
        $data = $form->validated();
        if (filled($data['ticket_id'] ?? null) && $this->ticketLabel($form->user(), (int) $data['ticket_id']) === null) {
            throw ValidationException::withMessages(['ticket_id' => __('room_access.requests.ticket_not_found')]);
        }
        if (filled($data['contract_id'] ?? null) && ! collect(app(ContractOptions::class)->handle((int) $data['contract_id']))->contains('id', (int) $data['contract_id'])) {
            throw ValidationException::withMessages(['contract_id' => __('room_access.requests.contract_not_found')]);
        }

        return $data;
    }
}
