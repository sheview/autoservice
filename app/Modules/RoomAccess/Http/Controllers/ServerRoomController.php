<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Contract\Actions\ListSites;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Actions\DeleteServerRoom;
use App\Modules\RoomAccess\Actions\SaveRoomAccessSettings;
use App\Modules\RoomAccess\Actions\SaveServerRoom;
use App\Modules\RoomAccess\Actions\SearchServerRooms;
use App\Modules\RoomAccess\Models\RoomApprovalStep;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomAccessSettings;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting up server rooms (room-access.manage): the rooms of each customer and site, who looks
 * after them and approves their requests, how their rules are accepted, when nobody may enter,
 * and the company's own terms added after every room's rules.
 */
class ServerRoomController extends Controller
{
    public function index(Request $request, ListCustomers $customers, TenantContext $context, SearchServerRooms $search): Response
    {
        $this->authorizeManage($request);
        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'customer_id' => $request->integer('customer_id') ?: null,
            'rules' => in_array($request->input('rules'), ['missing', 'set'], true) ? $request->input('rules') : null,
            'sort' => in_array($request->input('sort'), SearchServerRooms::SORTS, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
        $names = collect($customers->handle(withTrashed: true))->pluck('name', 'id');

        $rooms = $search->handle($filters)->paginate(20)->withQueryString()
            ->through(fn (ServerRoom $room) => [
                ...$room->only(['ulid', 'name', 'location', 'requires_id_number', 'is_active', 'missing_rules']),
                'customer' => $names[$room->customer_id] ?? '-',
                'rules' => $room->currentRules ? ['version' => $room->currentRules->version] : null,
                'versions' => $room->rule_versions_count,
                'updated_at' => $room->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('RoomAccess/Rooms/Index', [
            'rooms' => $rooms,
            'filters' => $filters,
            'customers' => $customers->handle(),
            // Rooms still without rules: the admin is told (requests to them are blocked or warned).
            'missingRules' => ServerRoom::query()->where('is_active', true)->whereDoesntHave('ruleVersions')->count(),
            'settings' => RoomAccessSettings::of($context->tenant()),
        ]);
    }

    public function create(Request $request, ListCustomers $customers, ListSites $sites, UsersWithPermission $users): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('RoomAccess/Rooms/Form', ['room' => null, ...$this->formOptions($customers, $sites, $users)]);
    }

    public function store(Request $request, SaveServerRoom $save): RedirectResponse
    {
        $this->authorizeManage($request);
        [$data, $managers, $approver] = $this->validated($request, null);
        $room = $save->handle(null, $data, $managers, $approver, $request->user());

        return redirect()->route('room-access.rooms.show', $room)->with('success', __('room_access.rooms.created'));
    }

    public function show(Request $request, ServerRoom $room, ListCustomers $customers, ListSites $sites): Response
    {
        $this->authorizeManage($request);
        $room->load(['ruleVersions' => fn ($q) => $q->orderByDesc('version'), 'approvalSteps', 'managers']);
        $users = User::query()->whereIn('id', [...$room->managers->pluck('user_id'), ...$room->approvalSteps->pluck('approver_user_id')->filter()])
            ->pluck('name', 'id');

        return Inertia::render('RoomAccess/Rooms/Show', [
            'room' => [
                ...$this->roomRow($room),
                'customer' => collect($customers->handle(withTrashed: true))->firstWhere('id', $room->customer_id)['name'] ?? '-',
                'site' => $room->site_id ? (collect($sites->handle(withTrashed: true))->firstWhere('id', $room->site_id)['name'] ?? null) : null,
                'managers' => $room->managers->map(fn ($m) => $users[$m->user_id] ?? '-')->values(),
                'approvers' => $room->approvalSteps->map(fn (RoomApprovalStep $step) => [
                    'position' => $step->position,
                    'side' => $step->side,
                    'name' => $step->approver_user_id ? ($users[$step->approver_user_id] ?? '-') : null,
                ])->values(),
            ],
            'versions' => $room->ruleVersions->map(fn (RoomRuleVersion $version) => [
                ...$version->only(['id', 'version', 'summary', 'received_from', 'note', 'created_by_name']),
                'effective_on' => $version->effective_on->toDateString(),
                'received_on' => $version->received_on?->toDateString(),
                'created_at' => $version->created_at->toIso8601String(),
                'file' => $version->hasMedia(RoomRuleVersion::FILE) ? route('room-access.rooms.rules.file', [$room, $version->id]) : null,
                'in_effect' => $version->effective_on->lte(today()),
            ])->values(),
            'currentVersion' => $room->ruleVersions->first(fn (RoomRuleVersion $v) => $v->effective_on->lte(today()))?->version,
        ]);
    }

    public function edit(Request $request, ServerRoom $room, ListCustomers $customers, ListSites $sites, UsersWithPermission $users): Response
    {
        $this->authorizeManage($request);
        $room->load(['approvalSteps', 'managers']);

        return Inertia::render('RoomAccess/Rooms/Form', [
            'room' => [
                ...$this->roomRow($room),
                'manager_ids' => $room->managers->pluck('user_id')->values(),
                'approver_user_id' => $room->approvalSteps->firstWhere('position', 1)?->approver_user_id,
            ],
            ...$this->formOptions($customers, $sites, $users),
        ]);
    }

    public function update(Request $request, ServerRoom $room, SaveServerRoom $save): RedirectResponse
    {
        $this->authorizeManage($request);
        [$data, $managers, $approver] = $this->validated($request, $room);
        $save->handle($room, $data, $managers, $approver, $request->user());

        return redirect()->route('room-access.rooms.show', $room)->with('success', __('room_access.rooms.updated'));
    }

    public function destroy(Request $request, ServerRoom $room, DeleteServerRoom $delete): RedirectResponse
    {
        $this->authorizeManage($request);
        $delete->handle($room);

        return redirect()->route('room-access.rooms.index')->with('success', __('room_access.rooms.deleted'));
    }

    /** The company's own terms and how long ID numbers are kept. */
    public function settings(Request $request, SaveRoomAccessSettings $save, TenantContext $context): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'company_terms' => ['array', 'max:'.RoomAccessSettings::MAX_TERMS],
            'company_terms.*' => ['nullable', 'string', 'max:500'],
            'id_retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
        ], [], __('room_access.fields'));
        $save->handle($context->tenant(), ['company_terms' => array_map('strval', array_filter($data['company_terms'] ?? [])), 'id_retention_days' => $data['id_retention_days']], $request->user());

        return back()->with('success', __('room_access.settings_saved'));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(RoomAccessSettings::PERMISSION) && $request->user()->customer_id === null, 403);
    }

    /** @return array<string, mixed> */
    private function roomRow(ServerRoom $room): array
    {
        return $room->only([
            'ulid', 'customer_id', 'site_id', 'name', 'location', 'requires_id_number', 'missing_rules', 'accept_mode', 'accept_on_enter',
            'entrants_accept_self', 'guard_link', 'freeze_periods', 'guard_contacts', 'is_active', 'notes',
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(ListCustomers $customers, ListSites $sites, UsersWithPermission $users): array
    {
        return [
            'customers' => $customers->handle(),
            'sites' => $sites->handle(),
            // Who may approve (a named approver) and our staff who may look after a room.
            'approvers' => $users->handle('room-access.approve')->map(fn (User $u) => $u->only(['id', 'name']))->sortBy('name')->values(),
            'staff' => $users->handle('room-access.view')->map(fn (User $u) => $u->only(['id', 'name']))->sortBy('name')->values(),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>, 2: int|null}
     */
    private function validated(Request $request, ?ServerRoom $room): array
    {
        $users = app(UsersWithPermission::class);
        $customerId = $request->integer('customer_id');
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'site_id' => ['nullable', 'integer', Rule::exists('customer_sites', 'id')->where('customer_id', $customerId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($room, $customerId) {
                $taken = ServerRoom::query()->where('customer_id', $customerId)->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($room, fn ($q) => $q->whereKeyNot($room->id))->exists();
                if ($taken) {
                    $fail(__('room_access.rooms.name_taken'));
                }
            }],
            'location' => ['nullable', 'string', 'max:1000'],
            'requires_id_number' => ['boolean'],
            'missing_rules' => ['required', Rule::in(ServerRoom::MISSING_RULES)],
            'accept_mode' => ['required', Rule::in(ServerRoom::ACCEPT_MODES)],
            'accept_on_enter' => ['boolean'],
            'entrants_accept_self' => ['boolean'],
            'guard_link' => ['boolean'],
            'freeze_periods' => ['array', 'max:50'],
            'freeze_periods.*.from' => ['required', 'date'],
            'freeze_periods.*.to' => ['required', 'date', 'after:freeze_periods.*.from'],
            'freeze_periods.*.reason' => ['nullable', 'string', 'max:255'],
            'guard_contacts' => ['array', 'max:10'],
            'guard_contacts.*.name' => ['required', 'string', 'max:255'],
            'guard_contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'guard_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'manager_ids' => ['array', 'max:50'],
            // Only our own staff of this company (UsersWithPermission is limited to it).
            'manager_ids.*' => ['integer', Rule::in($users->handle('room-access.view')->pluck('id')->all())],
            'approver_user_id' => ['nullable', 'integer', Rule::in($users->handle('room-access.approve')->pluck('id')->all())],
        ], [], __('room_access.fields'));

        $managers = array_values(array_unique(array_map('intval', $data['manager_ids'] ?? [])));
        $approver = $data['approver_user_id'] ?? null;
        unset($data['manager_ids'], $data['approver_user_id']);
        $data['freeze_periods'] = array_values($data['freeze_periods'] ?? []);
        $data['guard_contacts'] = array_values($data['guard_contacts'] ?? []);

        return [$data, $managers, $approver];
    }
}
