<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Asset\Actions\CategoryOptions;
use App\Modules\Asset\Actions\ItemRequestLines;
use App\Modules\Asset\Actions\StorageLocationOptions;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Document\Support\PhotoSlots;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\CreatePart;
use App\Modules\Inventory\Actions\DeletePart;
use App\Modules\Inventory\Actions\FindDuplicateParts;
use App\Modules\Inventory\Actions\FindPartUnits;
use App\Modules\Inventory\Actions\FindSimilarParts;
use App\Modules\Inventory\Actions\PartStats;
use App\Modules\Inventory\Actions\PartSuggestions;
use App\Modules\Inventory\Actions\PartUnitHistory;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Actions\SearchParts;
use App\Modules\Inventory\Actions\SearchPartUnits;
use App\Modules\Inventory\Actions\SearchStockMovements;
use App\Modules\Inventory\Exports\PartsExport;
use App\Modules\Inventory\Http\Requests\PartRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\TicketLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartController extends Controller
{
    public const EXPORT_PERMISSION = 'parts.export';

    // Turning serial number tracking on or off for a part, and picking it for a new one.
    public const SERIALS_PERMISSION = 'parts.serials';

    public function index(Request $request, SearchParts $search, PartStats $stats): Response
    {
        Gate::authorize('viewAny', Part::class);

        $filters = SearchParts::filtersFrom($request);
        $user = $request->user();
        $parts = $search->handle($filters)->paginate(50)->withQueryString();
        $parts->getCollection()->load('media');
        // Where each part of the page is kept (the shared storage locations).
        $locations = app(StorageLocationOptions::class)->names($parts->pluck('storage_location_id')->filter()->unique()->values()->all());

        return Inertia::render('Inventory/Parts/Index', [
            'parts' => $parts->through(fn (Part $part) => [
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'min_qty', 'qty_on_hand', 'is_active', 'track_serial']),
                'unit_cost' => Money::toBaht($part->unit_cost),
                'low' => $part->isLow(),
                'storage_location' => $locations[$part->storage_location_id] ?? null,
                'photo' => PhotoSlots::thumbnail($part, fn (int $slot) => route('inventory.parts.photos.show', [$part, $slot])),
            ]),
            'filters' => $filters,
            // Pieces whose serial number matches the search, with their history on the page.
            'serialHits' => app(FindPartUnits::class)->handle($filters['search']),
            'statuses' => SearchParts::STATUSES,
            'categories' => collect(app(CategoryOptions::class)->handle())->map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name']])->all(),
            'locations' => app(StorageLocationOptions::class)->handle($filters['storage_location_id']),
            'stockLevels' => SearchParts::STOCK_LEVELS,
            'stats' => $stats->handle(),
            'can' => [
                'create' => $user->can('create', Part::class),
                'update' => $user->can('parts.update'),
                'delete' => $user->can('parts.delete'),
                'import' => $user->can(PartImportController::PERMISSION),
                'export' => $user->can(self::EXPORT_PERMISSION),
                'categories' => $user->can('viewAny', AssetCategory::class),
                'receive' => $user->can('stock-movements.create'),
                'movements' => $user->can(StockMovementController::VIEW_PERMISSION),
            ],
        ]);
    }

    /**
     * Parts the one on the form would repeat (?name, ?part_number, ?except = the part edited), for
     * the form to show while typing: the same ones (blocking, as when saving), then those that
     * may be the same (FindSimilarParts, a hint only).
     */
    public function duplicates(Request $request, FindDuplicateParts $find, FindSimilarParts $similar): JsonResponse
    {
        abort_unless($request->user()->can('create', Part::class) || $request->user()->can('parts.update'), 403);

        $name = $request->string('name')->value();
        $number = $request->string('part_number')->value();
        $except = $request->integer('except') ?: null;
        $same = array_map(fn (array $part) => [...$part, 'blocking' => true], $find->handle($name, $number, $except));
        $alike = array_map(fn (array $part) => [...$part, 'blocking' => false],
            $similar->handle($name, $number, array_values(array_filter([$except, ...array_column($same, 'id')]))));

        return response()->json(array_map(fn (array $part) => [...$part, 'url' => route('inventory.parts.show', $part['id'])], [...$same, ...$alike]));
    }

    /**
     * The parts of the list (same filters) as Excel.
     */
    public function export(Request $request, SearchParts $search): BinaryFileResponse
    {
        abort_unless($request->user()->can('viewAny', Part::class) && $request->user()->can(self::EXPORT_PERMISSION), 403);

        return Excel::download(new PartsExport($search->handle(SearchParts::filtersFrom($request))), 'parts-'.now()->format('Ymd-His').'.xlsx');
    }

    public function create(Modules $modules): Response
    {
        Gate::authorize('create', Part::class);

        return Inertia::render('Inventory/Parts/Form', [
            'part' => null,
            'contracts' => $modules->enabled('contract') ? app(ContractOptions::class)->handle() : [],
            'categories' => app(CategoryOptions::class)->handle(),
            'locations' => app(StorageLocationOptions::class)->handle(),
            'canSerials' => request()->user()->can(self::SERIALS_PERMISSION),
            'picks' => app(PartSuggestions::class)->handle(),
            // The first stock is entered with the part (CreatePart), by whoever may enter receipts.
            'canReceive' => request()->user()->can(StockMovement::permissionFor(StockMovement::TYPE_RECEIVE)),
            'maxInitialSerials' => PartRequest::MAX_INITIAL_SERIALS,
        ]);
    }

    public function store(PartRequest $request, CreatePart $createPart): RedirectResponse
    {
        ['quantity' => $quantity, 'serials' => $serials] = $request->initialStock();
        $part = $createPart->handle($request->partData(), $quantity, $serials, $request->user());

        return redirect()->route('inventory.parts.show', $part)->with('success', __('inventory.parts.created'));
    }

    public function show(Request $request, Part $part, Modules $modules, TicketLabels $ticketLabels, ItemRequestLines $requestLines, SearchPartUnits $search): Response
    {
        Gate::authorize('view', $part);

        $user = $request->user();
        $movements = null;
        if ($user->can(StockMovementController::VIEW_PERMISSION)) {
            // Newest first, like the ledger page (id breaks ties within the same second); scope own = own entries.
            $movements = SearchStockMovements::visibleTo($part->movements()->getQuery(), $user)->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
            $tickets = $modules->enabled('service') ? $ticketLabels->handle($movements->pluck('ticket_id')->all()) : [];
            $movements = $movements->through(fn (StockMovement $movement) => [
                ...$movement->only(['id', 'type', 'quantity', 'balance_after', 'reference', 'note', 'user_name']),
                'unit_cost' => Money::toBaht($movement->unit_cost),
                'ticket' => $tickets[$movement->ticket_id] ?? null,
                'at' => $movement->created_at->toIso8601String(),
            ]);
        }

        return Inertia::render('Inventory/Parts/Show', [
            'part' => [
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'min_qty', 'qty_on_hand', 'is_active', 'notes', 'track_serial']),
                'unit_cost' => Money::toBaht($part->unit_cost),
                'low' => $part->isLow(),
                'category' => $part->asset_category_id ? app(CategoryNames::class)->handle(withTrashed: true)[$part->asset_category_id] ?? null : null,
                'storage_location' => $part->storage_location_id
                    ? app(StorageLocationOptions::class)->names([$part->storage_location_id])[$part->storage_location_id] ?? null
                    : null,
                // The MA contract (project) it is kept for.
                'contract' => $part->contract_id && $modules->enabled('contract')
                    ? app(ContractLabels::class)->handle([$part->contract_id])[$part->contract_id] ?? null
                    : null,
            ],
            'checkouts' => $modules->enabled('asset') ? $this->checkouts($user, $part, $requestLines) : null,
            'photos' => PhotoSlots::list($part, fn (int $slot) => route('inventory.parts.photos.show', [$part, $slot])),
            'movements' => $movements,
            // Pieces followed by serial number (also kept after tracking is turned off).
            'units' => $this->units($request, $part, $search),
            'unitFilters' => ['status' => $request->query('unit_status'), 'q' => $request->query('unit_q')],
            // Stock changes the user may enter here.
            'movementTypes' => array_values(array_filter(StockMovement::MANUAL_TYPES, fn (string $type) => $user->can(StockMovement::permissionFor($type)))),
            'can' => [
                'update' => $user->can('update', $part),
                'delete' => $user->can('delete', $part),
                'viewTickets' => $user->can('tickets.view'),
                'serials' => $user->can('update', $part) && $user->can(self::SERIALS_PERMISSION),
                'correct' => $user->can('stock-movements.create'),
            ],
        ]);
    }

    /** The pieces of the part on its page, 20 at a time, filtered by status and serial; null when it never had any. */
    private function units(Request $request, Part $part, SearchPartUnits $search): mixed
    {
        if (! $part->track_serial && ! $part->units()->exists()) {
            return null;
        }

        return $search->handle($part->id, ['status' => $request->query('unit_status'), 'q' => $request->query('unit_q')])
            ->paginate(20, pageName: 'units_page')->withQueryString()
            ->through(fn (PartUnit $unit) => PartUnitHistory::row($unit));
    }

    public function edit(Part $part, Modules $modules): Response
    {
        Gate::authorize('update', $part);

        return Inertia::render('Inventory/Parts/Form', [
            'part' => [
                ...$part->only(['id', 'code', 'name', 'contract_id', 'asset_category_id', 'storage_location_id', 'part_number', 'brand', 'unit', 'min_qty', 'is_active', 'notes', 'track_serial']),
                'unit_cost' => Money::toBaht($part->unit_cost),
            ],
            'contracts' => $modules->enabled('contract') ? app(ContractOptions::class)->handle($part->contract_id) : [],
            'categories' => app(CategoryOptions::class)->handle(),
            'locations' => app(StorageLocationOptions::class)->handle($part->storage_location_id),
            'canSerials' => request()->user()->can(self::SERIALS_PERMISSION),
            'picks' => app(PartSuggestions::class)->handle(),
        ]);
    }

    /**
     * Issue/loan requests on the part page (ItemRequestsPanel, Asset module): the lines of this
     * part and whether the user may start a request with it. A part short of stock may still be
     * asked for (what is missing is backordered). Null when the user has nothing to do with requests.
     *
     * @return array<string, mixed>|null
     */
    private function checkouts(User $user, Part $part, ItemRequestLines $requestLines): ?array
    {
        $panel = $requestLines->handle($user, CheckoutItem::TYPE_PART, $part->id);
        if ($panel === null) {
            return null;
        }

        return [
            'lines' => $panel['lines'],
            'available' => (bool) $part->is_active,
            'available_quantity' => max(0, (int) $part->qty_on_hand),
            'quantity' => (int) $part->qty_on_hand,
            'unit' => $part->unit,
            'can' => ['create' => $panel['can']['create'] && $part->is_active],
        ];
    }

    public function update(PartRequest $request, Part $part, SavePart $savePart): RedirectResponse
    {
        $savePart->handle($part, $request->partData());

        return redirect()->route('inventory.parts.show', $part)->with('success', __('inventory.parts.updated'));
    }

    public function destroy(Part $part, DeletePart $deletePart): RedirectResponse
    {
        Gate::authorize('delete', $part);

        $deletePart->handle($part);

        return redirect()->route('inventory.parts.index')->with('success', __('inventory.parts.deleted'));
    }
}
