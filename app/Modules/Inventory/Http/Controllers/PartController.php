<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\DeletePart;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Actions\SearchParts;
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
    public const EXPORT_PERMISSION = 'part.export';

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
                'update' => $user->can('part.update'),
                'delete' => $user->can('part.delete'),
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

    public function create(): Response
    {
        Gate::authorize('create', Part::class);

        return Inertia::render('Inventory/Parts/Form', ['part' => null]);
    }

    public function store(PartRequest $request, SavePart $savePart): RedirectResponse
    {
        $part = $savePart->handle(null, $request->partData());

        return redirect()->route('inventory.parts.show', $part)->with('success', __('inventory.parts.created'));
    }

    public function show(Request $request, Part $part, Modules $modules, TicketLabels $ticketLabels): Response
    {
        Gate::authorize('view', $part);

        $user = $request->user();
        $movements = null;
        if ($user->can('stock.view')) {
            $movements = $part->movements()->orderByDesc('id')->paginate(20)->withQueryString();
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
            ],
            'movements' => $movements,
            // Stock changes the user may enter here (each type has its own permission).
            'movementTypes' => array_values(array_filter(StockMovement::MANUAL_TYPES, fn (string $type) => $user->can("stock.{$type}"))),
            'can' => [
                'update' => $user->can('update', $part),
                'delete' => $user->can('delete', $part),
                'viewTickets' => $user->can('ticket.view'),
            ],
        ]);
    }

    public function edit(Part $part): Response
    {
        Gate::authorize('update', $part);

        return Inertia::render('Inventory/Parts/Form', [
            'part' => [
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'min_qty', 'is_active', 'notes']),
                'unit_cost' => Money::toBaht($part->unit_cost),
            ],
        ]);
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
