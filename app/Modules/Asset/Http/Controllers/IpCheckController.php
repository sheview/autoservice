<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Asset\Actions\AssignIp;
use App\Modules\Asset\Actions\FindFreeIps;
use App\Modules\Asset\Actions\IpRecord;
use App\Modules\Asset\Actions\IpTable;
use App\Modules\Asset\Actions\ReleaseIp;
use App\Modules\Asset\Actions\ReserveIps;
use App\Modules\Asset\Actions\SetIpExcluded;
use App\Modules\Asset\Actions\UpdateIpDetails;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpAssetHistory;
use App\Modules\Asset\Models\IpReservation;
use App\Modules\Asset\Models\Network;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Contract\Actions\ListSites;
use App\Modules\Identity\Actions\ContactPeople;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketsForIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Free IP" (simple IP address management): customer -> site -> network -> subnet -> address.
 * ip-check.view sees it; ip-check.run finds, reserves, gives out and releases addresses;
 * ip-check.manage keeps networks and subnets (NetworkController) and excludes addresses.
 * Staff only: customer accounts never see it.
 */
class IpCheckController extends Controller
{
    private const PER_PAGE = 50;

    private const SORTABLE = ['ip', 'status', 'hostname'];

    public function __construct(private IpTable $ipTable, private Modules $modules) {}

    public function index(Request $request, ListCustomers $listCustomers, ListSites $listSites, FindFreeIps $findFreeIps): Response
    {
        $user = $this->allow($request, 'view');

        $filters = [
            // "" = all, "own" = the company's own networks, or a customer id
            'customer' => (string) $request->input('customer', ''),
            'site' => $request->integer('site') ?: null,
            'network' => $request->integer('network') ?: null,
            'subnet' => $request->integer('subnet') ?: null,
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), IpAddress::STATUSES, true) ? $request->input('status') : '',
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'ip',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
        $customerId = $filters['customer'] === 'own' ? 0 : ((int) $filters['customer'] ?: null);

        $customers = $this->modules->enabled('contract') ? $listCustomers->handle() : [];
        $customerNames = collect($listCustomers->handle(withTrashed: true))->pluck('name', 'id');
        $siteNames = collect($listSites->handle(withTrashed: true))->pluck('name', 'id');

        $networks = Network::query()
            ->when($customerId === 0, fn ($q) => $q->whereNull('customer_id'))
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['site'], fn ($q, $id) => $q->where('site_id', $id))
            ->orderBy('name')
            ->get();
        $subnets = Subnet::query()
            ->with('network')
            ->whereIn('network_id', $networks->pluck('id'))
            ->when($filters['network'], fn ($q, $id) => $q->where('network_id', $id))
            ->orderBy('network_id')->orderBy('first_int')
            ->get();

        // Each subnet's table once: its counts for the cards, its rows for the list.
        $tables = $subnets->mapWithKeys(fn (Subnet $subnet) => [$subnet->id => $this->ipTable->handle($subnet, $user)]);
        $selected = $filters['subnet'] ? $subnets->firstWhere('id', $filters['subnet']) : null;

        $rows = null;
        if ($selected || $filters['search'] !== '' || $filters['status'] !== '') {
            $rows = $this->rows($selected ? collect([$selected]) : $subnets, $tables, $filters, $customerNames, $siteNames);
        }

        // "Find an address": the first free ones of a subnet.
        $findSubnet = $request->integer('find') ? $subnets->firstWhere('id', $request->integer('find')) : null;
        $count = min(max($request->integer('count', 1), 1), 50);

        return Inertia::render('Asset/IpCheck/Index', [
            'filters' => $filters,
            'customers' => $customers,
            'sites' => $customerId ? $listSites->handle($customerId) : [],
            'networks' => $networks->map(fn (Network $network) => [
                ...$network->only(['id', 'name', 'vlan_id', 'description', 'customer_id', 'site_id']),
                'customer' => $customerNames[$network->customer_id] ?? null,
                'site' => $siteNames[$network->site_id] ?? null,
            ])->values(),
            'subnets' => $subnets->map(fn (Subnet $subnet) => [
                ...$subnet->only(['id', 'network_id', 'cidr', 'gateway', 'description']),
                'network' => $subnet->network?->name,
                'customer' => $customerNames[$subnet->network?->customer_id] ?? null,
                'site' => $siteNames[$subnet->network?->site_id] ?? null,
                'summary' => IpTable::summary($tables[$subnet->id]),
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'cidr' => $selected->cidr,
                'summary' => IpTable::summary($tables[$selected->id]),
            ] : null,
            'rows' => $rows,
            'suggestions' => $findSubnet ? [
                'subnet_id' => $findSubnet->id,
                'cidr' => $findSubnet->cidr,
                'ips' => $findFreeIps->handle($findSubnet, $user, $count),
                'count' => $count,
            ] : null,
            'statuses' => IpAddress::STATUSES,
            'can' => [
                'run' => $user->can('ip-check.run'),
                'manage' => $user->can('ip-check.manage'),
            ],
        ]);
    }

    /** One address of a subnet, whether it has a record yet or not. */
    public function show(
        Request $request,
        Subnet $subnet,
        string $ip,
        ListCustomers $listCustomers,
        ListSites $listSites,
        UserNames $userNames,
        TicketsForIp $ticketsForIp,
        ContactPeople $contactPeople,
        AssetSummaries $assetSummaries,
    ): Response {
        $user = $this->allow($request, 'view');
        $row = collect($this->ipTable->handle($subnet, $user))->firstWhere('ip', $ip);
        abort_if($row === null, 404);

        $record = $row['ulid'] ? IpAddress::where('ulid', $row['ulid'])->first() : null;
        $network = $subnet->network;
        $reservations = $record ? $record->reservations()->get() : collect();
        $histories = $record ? $record->histories()->get() : collect();
        $names = $userNames->handle([
            $record?->responsible_id,
            ...$reservations->pluck('reserved_by')->all(),
            ...$histories->pluck('user_id')->all(),
        ]);
        $assetSearch = $request->string('asset_search')->trim()->value();

        return Inertia::render('Asset/IpCheck/Show', [
            'address' => [
                ...$row,
                'subnet_id' => $subnet->id,
                'cidr' => $subnet->cidr,
                'gateway' => $subnet->gateway,
                'network' => $network?->name,
                'vlan_id' => $network?->vlan_id,
                'customer' => collect($listCustomers->handle(withTrashed: true))->firstWhere('id', $network?->customer_id)['name'] ?? null,
                'site' => collect($listSites->handle(withTrashed: true))->firstWhere('id', $network?->site_id)['name'] ?? null,
                'responsible_id' => $record?->responsible_id,
                'responsible' => $names[$record?->responsible_id] ?? null,
                'in_use_since' => $record?->in_use_since?->toDateString(),
            ],
            'reservations' => $reservations->map(fn (IpReservation $r) => [
                ...$r->only(['id', 'purpose', 'notes', 'status']),
                'reserved_by' => $names[$r->reserved_by] ?? null,
                'reserved_at' => $r->reserved_at->toIso8601String(),
                'ended_at' => $r->ended_at?->toIso8601String(),
            ])->values(),
            'histories' => $histories->map(fn (IpAssetHistory $h) => [
                ...$h->only(['id', 'action', 'asset_code', 'hostname', 'mac_address', 'notes']),
                'user' => $names[$h->user_id] ?? null,
                'at' => $h->created_at->toIso8601String(),
            ])->values(),
            'tickets' => $record && $this->modules->enabled('service') && $user->can('tickets.view') ? $ticketsForIp->handle($record->id) : [],
            'staff' => $contactPeople->handle(null),
            // The asset picker of "give to a device": the network owner's assets.
            'assetOptions' => fn () => $assetSearch === '' ? [] : $assetSummaries->handle($user, [
                ...($network?->customer_id ? ['customer_id' => $network->customer_id] : []),
                'search' => $assetSearch,
                'limit' => 20,
            ]),
            'can' => [
                'run' => $user->can('ip-check.run'),
                'manage' => $user->can('ip-check.manage'),
                'viewAssets' => $user->can('assets.view'),
            ],
        ]);
    }

    /** An address by its record (links from tickets): its page under its subnet. */
    public function open(Request $request, IpAddress $address): RedirectResponse
    {
        $this->allow($request, 'view');

        return redirect()->route('asset.ip-check.ips.show', ['subnet' => $address->subnet_id, 'ip' => $address->ip]);
    }

    public function reserve(Request $request, Subnet $subnet, ReserveIps $reserveIps): RedirectResponse
    {
        $user = $this->allow($request, 'run');
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1', 'max:50'],
            'ips.*' => ['required', 'ip'],
            'purpose' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('asset.ipam.fields'));

        $reserved = $reserveIps->handle($subnet, $data['ips'], $user, $data['purpose'], $data['notes'] ?? null);

        // From an address's own page: stay there.
        $to = $request->boolean('back') ? back() : redirect()->route('asset.ip-check', ['subnet' => $subnet->id, 'customer' => $request->input('customer')]);

        return $to->with('success', __('asset.ipam.reserved', ['ips' => collect($reserved)->pluck('ip')->implode(', ')]));
    }

    public function assign(Request $request, Subnet $subnet, string $ip, IpRecord $ipRecord, AssignIp $assignIp): RedirectResponse
    {
        $user = $this->allow($request, 'run');
        $data = $request->validate([
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->whereNull('deleted_at')],
            ...$this->detailRules(),
        ], attributes: __('asset.ipam.fields'));

        $assignIp->handle($this->record($ipRecord, $subnet, $ip), $data, $user);

        return back()->with('success', __('asset.ipam.assigned', ['ip' => $ip]));
    }

    public function release(Request $request, Subnet $subnet, string $ip, IpRecord $ipRecord, ReleaseIp $releaseIp): RedirectResponse
    {
        $user = $this->allow($request, 'run');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $releaseIp->handle($this->record($ipRecord, $subnet, $ip), $user, $data['notes'] ?? null);

        return back()->with('success', __('asset.ipam.released', ['ip' => $ip]));
    }

    public function exclude(Request $request, Subnet $subnet, string $ip, IpRecord $ipRecord, SetIpExcluded $setIpExcluded): RedirectResponse
    {
        $user = $this->allow($request, 'manage');
        $data = $request->validate(['excluded' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);

        $setIpExcluded->handle($this->record($ipRecord, $subnet, $ip), (bool) $data['excluded'], $user, $data['notes'] ?? null);

        return back()->with('success', __('asset.ipam.saved'));
    }

    public function update(Request $request, Subnet $subnet, string $ip, IpRecord $ipRecord, UpdateIpDetails $updateIpDetails): RedirectResponse
    {
        $user = $this->allow($request, 'run');
        $data = $request->validate($this->detailRules(), attributes: __('asset.ipam.fields'));

        $updateIpDetails->handle($this->record($ipRecord, $subnet, $ip), $data, $user);

        return back()->with('success', __('asset.ipam.saved'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function detailRules(): array
    {
        return [
            'hostname' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/'],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'in_use_since' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function record(IpRecord $ipRecord, Subnet $subnet, string $ip): IpAddress
    {
        $record = $ipRecord->handle($subnet, $ip);
        abort_if($record === null, 404);

        return $record;
    }

    private function allow(Request $request, string $action): User
    {
        if ($request->filled('mac_address')) {
            $request->merge(['mac_address' => strtoupper(str_replace('-', ':', trim((string) $request->input('mac_address'))))]);
        }
        $user = $request->user();
        abort_unless($user->can("ip-check.{$action}"), 403);
        abort_if($user->customer_id !== null, 403);

        return $user;
    }

    /**
     * The rows of the subnets, filtered, sorted and cut into pages.
     *
     * @param  Collection<int, Subnet>  $subnets
     * @param  Collection<int, list<array<string, mixed>>>  $tables
     * @param  array<string, mixed>  $filters
     */
    private function rows($subnets, $tables, array $filters, $customerNames, $siteNames): LengthAwarePaginator
    {
        $needle = mb_strtolower($filters['search']);
        $matches = function (array $row) use ($needle): bool {
            if ($needle === '') {
                return true;
            }
            $texts = [$row['ip'], $row['hostname'], $row['mac_address']];
            foreach ($row['assets'] as $asset) {
                array_push($texts, $asset['asset_code'], $asset['serial_number'], $asset['name']);
            }

            return collect($texts)->filter()->contains(fn (string $text) => str_contains(mb_strtolower($text), $needle));
        };

        $all = $subnets->flatMap(fn (Subnet $subnet) => collect($tables[$subnet->id])->map(fn (array $row) => [
            ...$row,
            'subnet_id' => $subnet->id,
            'cidr' => $subnet->cidr,
            'site' => $siteNames[$subnet->network?->site_id] ?? null,
            'customer' => $customerNames[$subnet->network?->customer_id] ?? null,
        ]))
            ->filter(fn (array $row) => $filters['status'] === '' || $row['status'] === $filters['status'])
            ->filter($matches);

        $sorted = $all->sortBy(
            fn (array $row) => match ($filters['sort']) {
                'status' => array_search($row['status'], IpAddress::STATUSES, true) * 2 ** 33 + $row['ip_int'],
                'hostname' => mb_strtolower($row['hostname'] ?? "\u{FFFF}").' '.str_pad((string) $row['ip_int'], 10, '0', STR_PAD_LEFT),
                default => $row['ip_int'],
            },
            descending: $filters['direction'] === 'desc',
        )->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator($sorted->forPage($page, self::PER_PAGE)->values(), $sorted->count(), self::PER_PAGE, $page, [
            'path' => route('asset.ip-check'),
        ]))->withQueryString();
    }
}
