<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\CorrectPartUnit;
use App\Modules\Inventory\Actions\PartUnitHistory;
use App\Modules\Inventory\Actions\SearchPartUnits;
use App\Modules\Inventory\Actions\StartTrackingSerials;
use App\Modules\Inventory\Actions\StopTrackingSerials;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Support\PartSerials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pieces of parts followed by serial number: choosing them (pickers), their history, correcting
 * one (stock-movements.create), and turning tracking on or off for a part (parts.serials).
 */
class PartUnitController extends Controller
{
    /**
     * Pieces to choose from, as JSON: in stock to issue, or out (of a ticket / request line) to take back.
     */
    public function options(Request $request, Part $part, SearchPartUnits $search): JsonResponse
    {
        Gate::authorize('view', $part);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', PartUnit::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'ticket_id' => ['nullable', 'integer'],
            'checkout_item_id' => ['nullable', 'integer'],
        ]);

        return response()->json($search->handle($part->id, $filters + ['status' => PartUnit::STATUS_IN_STOCK])
            ->limit(100)->get()->map(fn (PartUnit $unit) => PartUnitHistory::row($unit))->values());
    }

    /** The history of one piece, as JSON. */
    public function history(Request $request, PartUnit $unit, PartUnitHistory $history): JsonResponse
    {
        Gate::authorize('view', $unit->part);

        return response()->json([
            'unit' => PartUnitHistory::row($unit),
            'part' => $unit->part->only(['id', 'code', 'name']),
            'events' => $history->handle([$unit->id])[$unit->id] ?? [],
        ]);
    }

    public function update(Request $request, PartUnit $unit, CorrectPartUnit $correct): RedirectResponse
    {
        abort_unless($request->user()->can('view', $unit->part) && $request->user()->can('stock-movements.create'), 403);
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:'.PartSerials::MAX_LENGTH],
            'warranty_until' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [], __('inventory.fields'));

        $correct->handle($unit, $data, $data['reason'], $request->user());

        return back()->with('success', __('inventory.units.corrected'));
    }

    /** Turning tracking on: the serials of every piece on hand first. */
    public function start(Request $request, Part $part): Response|RedirectResponse
    {
        $this->authorizeSettings($request, $part);
        if ($part->track_serial) {
            return redirect()->route('inventory.parts.show', $part);
        }

        return Inertia::render('Inventory/Parts/StartTracking', [
            'part' => $part->only(['id', 'code', 'name', 'unit', 'qty_on_hand']),
            // Pieces still "in stock" from an earlier time the part was tracked.
            'kept' => $part->units()->where('status', PartUnit::STATUS_IN_STOCK)->orderBy('id')->get()
                ->map(fn (PartUnit $unit) => PartUnitHistory::row($unit)),
        ]);
    }

    public function storeStart(Request $request, Part $part, StartTrackingSerials $startTracking): RedirectResponse
    {
        $this->authorizeSettings($request, $part);
        $data = $request->validate([
            'keep_ids' => ['array'],
            'keep_ids.*' => ['integer'],
            'serials' => ['array', 'max:5000'],
            'serials.*' => ['nullable', 'string', 'max:'.PartSerials::MAX_LENGTH],
        ], [], __('inventory.fields'));

        $startTracking->handle($part, $data['keep_ids'] ?? [], PartSerials::clean($data['serials'] ?? []), $request->user());

        return redirect()->route('inventory.parts.show', $part)->with('success', __('inventory.units.started'));
    }

    public function stop(Request $request, Part $part, StopTrackingSerials $stopTracking): RedirectResponse
    {
        $this->authorizeSettings($request, $part);
        $stopTracking->handle($part, $request->user());

        return back()->with('success', __('inventory.units.stopped'));
    }

    private function authorizeSettings(Request $request, Part $part): void
    {
        abort_unless($request->user()->can('update', $part) && $request->user()->can(PartCategoryController::PERMISSION), 403);
    }
}
