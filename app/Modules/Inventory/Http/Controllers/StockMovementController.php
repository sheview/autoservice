<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Actions\RecordTrackedMovement;
use App\Modules\Inventory\Actions\SearchStockMovements;
use App\Modules\Inventory\Http\Requests\StockMovementRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketLabels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    /** The ledger shows who took what, so it has its own permission on top of parts.view; scope own = the user's own entries. */
    public const VIEW_PERMISSION = 'stock-movements.view';

    public function index(Request $request, SearchStockMovements $search, Modules $modules, TicketLabels $ticketLabels): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Part::class) && $user->can(self::VIEW_PERMISSION), 403);

        $filters = SearchStockMovements::filtersFrom($request);
        $movements = $search->handle($filters, $user)->paginate(30)->withQueryString();
        $tickets = $modules->enabled('service') ? $ticketLabels->handle($movements->pluck('ticket_id')->all()) : [];

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements->through(fn (StockMovement $movement) => [
                ...$movement->only(['id', 'type', 'quantity', 'balance_after', 'reference', 'note', 'user_name']),
                'part' => $movement->part ? [
                    ...$movement->part->only(['id', 'code', 'name', 'unit']),
                    'deleted' => $movement->part->trashed(),
                ] : null,
                'ticket' => $tickets[$movement->ticket_id] ?? null,
                'at' => $movement->created_at->toIso8601String(),
            ]),
            'filters' => $filters,
            'types' => StockMovement::TYPES,
            // The part the list is narrowed to (from the part page), for the heading.
            'part' => $filters['part_id'] ? Part::withTrashed()->find($filters['part_id'])?->only(['id', 'code', 'name']) : null,
            'can' => ['viewTickets' => $user->can('tickets.view')],
        ]);
    }

    public function store(StockMovementRequest $request, Part $part, RecordStockMovement $recordMovement, RecordTrackedMovement $recordTracked): RedirectResponse
    {
        $type = $request->validated('type');

        if ($request->tracked()) {
            $result = $recordTracked->handle($part, $type, $request->details(), $request->user());
            if ($result['elsewhere'] !== []) {
                return back()->with('success', __("inventory.movements.recorded.{$type}"))
                    ->with('warning', __('inventory.units.saved_with_warning', ['lines' => implode(' · ', $result['elsewhere'])]));
            }
        } else {
            $recordMovement->handle($part, $type, (int) $request->validated('quantity'), $request->user(), $request->details());
        }

        return back()->with('success', __("inventory.movements.recorded.{$type}"));
    }
}
