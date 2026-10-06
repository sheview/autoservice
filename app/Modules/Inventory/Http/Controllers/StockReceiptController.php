<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Support\PartSerials;
use App\Modules\Platform\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receiving goods into stock without a purchase request (stock-movements.create): any part, by
 * quantity or, for a part followed by serial number, by one serial per unit.
 */
class StockReceiptController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorizeReceive($request);
        $part = $request->integer('part') ? Part::query()->where('is_active', true)->find($request->integer('part')) : null;

        return Inertia::render('Inventory/Receive', ['part' => $part ? $this->option($part) : null]);
    }

    /** Active parts by code or name, for choosing what came. */
    public function parts(Request $request): JsonResponse
    {
        $this->authorizeReceive($request);
        $q = addcslashes(trim((string) $request->query('q', '')), '%_\\');

        return response()->json(Part::query()->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->limit(30)->get()->map(fn (Part $part) => $this->option($part)));
    }

    public function store(Request $request, ReceiveStock $receive): RedirectResponse
    {
        $this->authorizeReceive($request);
        $data = $request->validate([
            'part_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:5000'],
            'serials' => ['nullable', 'array', 'max:5000'],
            'serials.*' => ['nullable', 'string', 'max:'.PartSerials::MAX_LENGTH],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], // baht
            'supplier' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'warranty_until' => ['nullable', 'date'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], __('inventory.fields'));

        // Parts of another company are not found (tenant scope + RLS).
        $part = Part::query()->where('is_active', true)->find($data['part_id']);
        abort_if($part === null, 404);
        $result = $receive->handle($part, (int) $data['quantity'], PartSerials::clean($data['serials'] ?? []), $request->user(), [
            'unit_cost' => Money::toSatang($data['unit_cost'] ?? null),
            'supplier' => $data['supplier'] ?? null,
            'reference' => $data['reference'] ?? null,
            'warranty_until' => $data['warranty_until'] ?? null,
            'received_on' => $data['received_on'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        $response = redirect()->route('inventory.parts.show', $part)
            ->with('success', __('inventory.units.received', ['count' => $data['quantity']]));

        return $result['elsewhere'] === [] ? $response
            : $response->with('warning', __('inventory.units.saved_with_warning', ['lines' => implode(' · ', $result['elsewhere'])]));
    }

    private function authorizeReceive(Request $request): void
    {
        abort_unless($request->user()->can('viewAny', Part::class) && $request->user()->can('stock-movements.create'), 403);
    }

    /** @return array<string, mixed> */
    private function option(Part $part): array
    {
        return [
            ...$part->only(['id', 'code', 'name', 'unit', 'brand', 'part_number', 'track_serial', 'qty_on_hand']),
            'unit_cost' => Money::toBaht($part->unit_cost),
        ];
    }
}
