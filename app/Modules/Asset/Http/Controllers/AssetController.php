<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetSuggestions;
use App\Modules\Asset\Actions\CheckedOutQuantities;
use App\Modules\Asset\Actions\CreateAsset;
use App\Modules\Asset\Actions\DeleteAsset;
use App\Modules\Asset\Actions\RequestCheckout;
use App\Modules\Asset\Actions\SameModelAssets;
use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Asset\Actions\SearchCheckouts;
use App\Modules\Asset\Exports\AssetsExport;
use App\Modules\Asset\Http\Requests\AssetRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Actions\ContractsForAsset;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Support\Attachments;
use App\Modules\Document\Support\PhotoSlots;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\PurchaseRequestDetails;
use App\Modules\Maintenance\Actions\PmHistoryForAsset;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\TicketsForAsset;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController extends Controller
{
    public function __construct(
        private Modules $modules,
        private ListCustomers $listCustomers,
        private AssetSuggestions $suggestions,
    ) {}

    public function index(Request $request, SearchAssets $search, CheckedOutQuantities $checkedOut): Response
    {
        Gate::authorize('viewAny', Asset::class);

        $filters = SearchAssets::filtersFrom($request);
        $user = $request->user();
        $customerNames = collect($this->customers(withTrashed: true))->pluck('name', 'id');

        $assets = $search->handle($user, $filters)
            ->with(['category:id,name', 'branch:id,name'])
            ->paginate(20)
            ->withQueryString();
        // Held by issue/loan forms that are asked for or out; the rest of each asset is available.
        $held = $checkedOut->handle($assets->getCollection()->modelKeys());

        $assets = $assets
            ->through(fn (Asset $asset) => [
                'quantity' => $asset->quantity,
                'available' => max(0, $asset->quantity - ($held[$asset->id] ?? 0)),
                'unit' => $asset->unit,
                'ulid' => $asset->ulid,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'brand_model' => trim("{$asset->brand} {$asset->model}") ?: null,
                'serial_number' => $asset->serial_number,
                'property_no' => $asset->property_no,
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
                'customer' => $customerNames[$asset->customer_id] ?? null,
                'status' => $asset->status,
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
            ]);

        return Inertia::render('Asset/Assets/Index', [
            'assets' => $assets,
            'filters' => $filters,
            'branches' => $this->branchOptions($user, 'assets.view'),
            'customers' => $this->customers(),
            'categories' => AssetCategory::orderBy('name')->get(['id', 'name']),
            'statuses' => Asset::STATUSES,
            'expiringDays' => SearchAssets::EXPIRING_DAYS,
            'can' => [
                'create' => $user->can('create', Asset::class),
                'import' => $user->can('import', Asset::class),
                'export' => $user->can('export', Asset::class),
            ],
        ]);
    }

    /**
     * ?from={ulid}: start from a copy of that asset (same model), with no code, serial or
     * equipment number, to add more devices of that model.
     * ?purchase_request={ulid}: register what a received purchase request brought (Inventory
     * module): its name, price and date, one row per unit bought.
     */
    public function create(Request $request, PurchaseRequestDetails $purchaseRequestDetails): Response
    {
        Gate::authorize('create', Asset::class);

        $from = $request->filled('from') ? Asset::where('ulid', $request->string('from'))->first() : null;
        if ($from !== null && ! $request->user()->can('view', $from)) {
            $from = null;
        }

        $purchase = $request->filled('purchase_request') && $this->modules->enabled('inventory')
            ? $purchaseRequestDetails->handle($request->string('purchase_request')->value(), $request->user())
            : null;

        return Inertia::render('Asset/Assets/Form', [
            ...$this->formProps($request->user(), null),
            'copy' => match (true) {
                $from !== null => [
                    ...collect($this->assetForm($from))->except(['ulid', 'asset_code', 'serials', 'property_no'])->all(),
                    'asset_code' => $from->asset_code,
                ],
                $purchase !== null => [
                    'name' => $purchase['item_name'],
                    'status' => Asset::STATUS_SPARE,
                    'purchased_at' => $purchase['received_on'],
                    'purchase_price' => $purchase['unit_price'],
                    'quantity' => $purchase['quantity'],
                    'notes' => __('asset.assets.from_purchase', ['no' => $purchase['pr_no'], 'name' => $purchase['requested_by_name'] ?? '-']),
                ],
                default => null,
            },
            'purchase' => $purchase ? ['pr_no' => $purchase['pr_no'], 'quantity' => $purchase['quantity']] : null,
        ]);
    }

    public function store(AssetRequest $request, CreateAsset $createAsset): RedirectResponse
    {
        $asset = $createAsset->handle(
            $request->assetData(),
            $request->serials(),
            $request->attachments(),
            $request->handedOut(),
            $request->user(),
        );

        return redirect()->route('asset.assets.show', $asset)->with('success', __('asset.assets.created', ['code' => $asset->asset_code]));
    }

    public function show(
        Request $request,
        Asset $asset,
        ContractsForAsset $contractsForAsset,
        TicketsForAsset $ticketsForAsset,
        PmHistoryForAsset $pmHistoryForAsset,
        SameModelAssets $sameModelAssets,
        UsersWithPermission $usersWithPermission,
    ): Response {
        Gate::authorize('view', $asset);

        $asset->load(['category', 'branch:id,name', 'serials']);
        $user = $request->user();
        $showContracts = $this->modules->enabled('contract') && $user->can('contracts.view');
        $serviceOn = $this->modules->enabled('service');

        return Inertia::render('Asset/Assets/Show', [
            'asset' => [
                ...$asset->only([
                    'ulid', 'asset_code', 'name', 'brand', 'model', 'subtype', 'serial_number', 'quantity', 'unit', 'property_no', 'status',
                    'location', 'ip_address', 'mac_address', 'used_by', 'department', 'notes',
                ]),
                'serials' => $asset->serials->pluck('serial_number'),
                'available' => RequestCheckout::availableQuantity($asset),
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
                'customer' => collect($this->customers(withTrashed: true))->firstWhere('id', $asset->customer_id)['name'] ?? null,
                'purchased_at' => $asset->purchased_at?->toDateString(),
                'purchase_price' => Money::toBaht($asset->purchase_price),
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                'specs' => collect($asset->category?->spec_fields ?? [])
                    ->map(fn (array $field) => ['label' => $field['label'], 'value' => $asset->specs[$field['key']] ?? null])
                    ->values(),
            ],
            // Every device of the same category, brand and model (this one included).
            'sameModel' => $sameModelAssets->handle($user, $asset),
            'checkouts' => $this->checkouts($request, $asset, $usersWithPermission),
            'photos' => PhotoSlots::list($asset, fn (int $slot) => route('asset.assets.photos.show', [$asset, $slot])),
            'attachments' => Attachments::list($asset, $asset->attachmentCollection(), fn (int $id) => route('asset.assets.attachments.show', [$asset, $id])),
            // null = the user cannot see contracts here (module off or no contracts.view)
            'contracts' => $showContracts ? $contractsForAsset->handle($asset->id) : null,
            // null = the user cannot see tickets here (module off or no tickets.view)
            'tickets' => $serviceOn && $user->can('tickets.view') ? $ticketsForAsset->handle($asset->id) : null,
            // null = the user cannot see PM rounds here (module off or no pm-visits.view)
            'pmHistory' => $this->modules->enabled('maintenance') && $user->can('pm-visits.view') ? $pmHistoryForAsset->handle($asset->id) : null,
            'history' => $asset->activities()->latest('id')->limit(20)->get()->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor' => $log->properties['actor']['name'] ?? null,
                'changed' => array_keys($log->properties['attributes'] ?? []),
                'at' => $log->created_at->toIso8601String(),
            ]),
            'can' => [
                'create' => $user->can('create', Asset::class),
                'update' => $user->can('update', $asset),
                'delete' => $user->can('delete', $asset),
                'openTicket' => $serviceOn && $user->can('tickets.create'),
                'printLabel' => $this->modules->enabled('labeling') && $user->can('labels.print'),
            ],
        ]);
    }

    public function edit(Request $request, Asset $asset): Response
    {
        Gate::authorize('update', $asset);

        return Inertia::render('Asset/Assets/Form', $this->formProps($request->user(), $asset));
    }

    public function update(AssetRequest $request, Asset $asset, SaveAsset $saveAsset, AddAttachments $addAttachments): RedirectResponse
    {
        $saveAsset->handle($asset, $request->assetData(), $request->serials());
        $addAttachments->handle($asset, $request->attachments());

        return redirect()->route('asset.assets.show', $asset)->with('success', __('asset.assets.updated'));
    }

    public function destroy(Asset $asset, DeleteAsset $deleteAsset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        $deleteAsset->handle($asset);

        return redirect()->route('asset.assets.index')->with('success', __('asset.assets.deleted', ['code' => $asset->asset_code]));
    }

    /**
     * The assets of the list (same filters) as Excel.
     */
    public function export(Request $request, SearchAssets $search): BinaryFileResponse
    {
        Gate::authorize('export', Asset::class);

        $query = $search->handle($request->user(), SearchAssets::filtersFrom($request));

        return Excel::download($this->sheet($query), 'assets-'.now()->format('Ymd-His').'.xlsx');
    }

    /**
     * An empty sheet with the import headings.
     */
    public function template(): BinaryFileResponse
    {
        Gate::authorize('import', Asset::class);

        return Excel::download($this->sheet(Asset::query()->whereRaw('false')), 'asset-import-template.xlsx');
    }

    private function sheet($query): AssetsExport
    {
        // Spec fields of every category (key => label), one Excel column each.
        $specFields = AssetCategory::orderBy('name')->get(['spec_fields'])
            ->flatMap(fn (AssetCategory $category) => $category->spec_fields)
            ->unique('key')
            ->mapWithKeys(fn (array $field) => [$field['key'] => $field['label']])
            ->all();

        $customerCodes = collect($this->customers(withTrashed: true))->pluck('code', 'id')->all();

        return new AssetsExport($query, $specFields, $customerCodes);
    }

    private function formProps(User $user, ?Asset $asset): array
    {
        return [
            'asset' => $asset ? $this->assetForm($asset) : null,
            'attachments' => $asset
                ? Attachments::list($asset, $asset->attachmentCollection(), fn (int $id) => route('asset.assets.attachments.show', [$asset, $id]))
                : [],
            'copy' => null,
            'purchase' => null,
            'maxSerials' => AssetRequest::MAX_SERIALS,
            'categories' => AssetCategory::orderBy('name')->get(['id', 'name', 'code_prefix', 'requires_serial', 'spec_fields']),
            'branches' => $this->branchOptions($user, $asset ? 'assets.update' : 'assets.create'),
            'customers' => $this->customers(),
            'statuses' => Asset::STATUSES,
            // Creating only: the asset is already out with someone.
            'handedOutStatuses' => $asset ? [] : array_keys(AssetRequest::HANDED_OUT),
            ...$this->suggestions->handle(),
        ];
    }

    /**
     * Issue/loan on the asset page: the form in progress, the last ones, and whether a new one can
     * be asked for (with the staff to choose from). Null when the user has nothing to do with them.
     *
     * @return array<string, mixed>|null
     */
    private function checkouts(Request $request, Asset $asset, UsersWithPermission $usersWithPermission): ?array
    {
        $user = $request->user();
        $can = AssetCheckoutController::abilities($request);
        if (! $can['view'] && ! $can['request'] && ! $can['approve'] && ! $can['return']) {
            return null;
        }

        // Every form still holding some of the asset (several at once for an asset bought by the
        // lot), then the last closed ones: those the user reaches (SearchCheckouts).
        $forms = fn () => SearchCheckouts::visibleTo(AssetCheckout::query(), $user)->where('asset_id', $asset->id)->with('asset:id,branch_id');
        $open = $forms()->whereIn('status', AssetCheckout::OPEN_STATUSES)->oldest('id')->get();
        $recent = $forms()->whereNotIn('status', AssetCheckout::OPEN_STATUSES)->latest('id')->limit(10)->get();
        $available = RequestCheckout::available($asset);
        $row = fn (AssetCheckout $checkout) => AssetCheckoutController::row($checkout, $user);

        return [
            // The first open form (kept for pages that show one), and all of them.
            'current' => $open->isNotEmpty() ? $row($open->first()) : null,
            'open' => $open->map($row)->values(),
            'history' => $recent->map($row)->values(),
            'available' => $available,
            'available_quantity' => RequestCheckout::availableQuantity($asset),
            'quantity' => $asset->quantity,
            'unit' => $asset->unit,
            // Asking only for oneself (forSelf): nobody to choose.
            'borrowers' => $available && $can['request'] && ! $can['forSelf']
                ? $usersWithPermission->handle('assets.view')->sortBy('name')->map(fn ($u) => $u->only(['id', 'name']))->values()
                : [],
            'contracts' => $available && $can['request'] && $this->modules->enabled('contract') ? app(ContractOptions::class)->handle() : [],
            'can' => [...$can, 'userId' => $user->id],
        ];
    }

    /**
     * An asset's fields as the form edits them.
     *
     * @return array<string, mixed>
     */
    private function assetForm(Asset $asset): array
    {
        return [
            ...$asset->only([
                'ulid', 'asset_code', 'name', 'category_id', 'branch_id', 'customer_id', 'brand', 'model', 'subtype',
                'quantity', 'unit', 'property_no', 'status', 'location', 'ip_address', 'mac_address', 'used_by', 'department', 'notes', 'specs',
            ]),
            'owner' => $asset->customer_id === null ? 'company' : 'customer',
            'serials' => $asset->serials()->pluck('serial_number')->all(),
            'purchased_at' => $asset->purchased_at?->toDateString(),
            'purchase_price' => Money::toBaht($asset->purchase_price),
            'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
        ];
    }

    /**
     * Customers (Contract module) to pick from; none when the tenant has the module off.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function customers(bool $withTrashed = false): array
    {
        $customers = $this->modules->enabled('contract') ? $this->listCustomers->handle($withTrashed) : [];

        // A customer account only ever learns about its own customer.
        $own = request()->user()?->customer_id;

        return $own === null ? $customers : array_values(array_filter($customers, fn (array $c) => $c['id'] === $own));
    }

    /**
     * Branches the user may pick: all of them when $permission reaches the whole company (scope
     * all), otherwise only their own.
     */
    private function branchOptions(User $user, string $permission): array
    {
        return Branch::query()
            ->unless(DataScope::of($user, $permission) === PermissionCatalog::SCOPE_ALL, fn ($q) => $q->whereKey($user->branch_id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }
}
