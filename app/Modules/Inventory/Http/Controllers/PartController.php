<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\ItemRequestLines;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Document\Support\PhotoSlots;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\DeletePart;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Actions\SearchParts;
use App\Modules\Inventory\Actions\SearchStockMovements;
use App\Modules\Inventory\Exports\PartsExport;
use App\Modules\Inventory\Http\Requests\PartRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\TicketLabels;
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

    public function index(Request $request, SearchParts $search): Response
    {
        Gate::authorize('viewAny', Part::class);

        $filters = SearchParts::filtersFrom($request);
        $user = $request->user();

        return Inertia::render('Inventory/Parts/Index', [
            'parts' => $search->handle($filters)->paginate(20)->withQueryString()->through(fn (Part $part) => [
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'min_qty', 'qty_on_hand', 'is_active']),
                'unit_cost' => Money::toBaht($part->unit_cost),
                'low' => $part->isLow(),
            ]),
            'filters' => $filters,
            'statuses' => SearchParts::STATUSES,
            'stockLevels' => SearchParts::STOCK_LEVELS,
            'can' => [
                'create' => $user->can('create', Part::class),
                'update' => $user->can('parts.update'),
                'delete' => $user->can('parts.delete'),
                'import' => $user->can(PartImportController::PERMISSION),
                'export' => $user->can(self::EXPORT_PERMISSION),
            ],
        ]);
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
        ]);
    }

    public function store(PartRequest $request, SavePart $savePart): RedirectResponse
    {
        $part = $savePart->handle(null, $request->partData());

        return redirect()->route('inventory.parts.show', $part)->with('success', __('inventory.parts.created'));
    }

    public function show(Request $request, Part $part, Modules $modules, TicketLabels $ticketLabels, ItemRequestLines $requestLines): Response
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
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'min_qty', 'qty_on_hand', 'is_active', 'notes']),
                'unit_cost' => Money::toBaht($part->unit_cost),
                'low' => $part->isLow(),
                // The MA contract (project) it is kept for.
                'contract' => $part->contract_id && $modules->enabled('contract')
                    ? app(ContractLabels::class)->handle([$part->contract_id])[$part->contract_id] ?? null
                    : null,
            ],
            'checkouts' => $modules->enabled('asset') ? $this->checkouts($user, $part, $requestLines) : null,
            'photos' => PhotoSlots::list($part, fn (int $slot) => route('inventory.parts.photos.show', [$part, $slot])),
            'movements' => $movements,
            // Stock changes the user may enter here.
            'movementTypes' => array_values(array_filter(StockMovement::MANUAL_TYPES, fn (string $type) => $user->can(StockMovement::permissionFor($type)))),
            'can' => [
                'update' => $user->can('update', $part),
                'delete' => $user->can('delete', $part),
                'viewTickets' => $user->can('tickets.view'),
            ],
        ]);
    }

    public function edit(Part $part, Modules $modules): Response
    {
        Gate::authorize('update', $part);

        return Inertia::render('Inventory/Parts/Form', [
            'part' => [
                ...$part->only(['id', 'code', 'name', 'contract_id', 'part_number', 'brand', 'unit', 'min_qty', 'is_active', 'notes']),
                'unit_cost' => Money::toBaht($part->unit_cost),
            ],
            'contracts' => $modules->enabled('contract') ? app(ContractOptions::class)->handle($part->contract_id) : [],
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
