<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\StockMovement;

/**
 * Parts used on a ticket: what was issued to it minus what came back, per part; for a part
 * followed by serial number also the pieces still on the ticket.
 * For the Service module, which must not use the Inventory models directly.
 */
class TicketParts
{
    /**
     * @return list<array{part_id: int, code: string, name: string, unit: string, quantity: int, types: list<string>,
     *     track_serial: bool, units: list<array{id: int, serial_number: string}>}>
     */
    public function handle(int $ticketId): array
    {
        $used = StockMovement::query()
            ->where('ticket_id', $ticketId)
            ->groupBy('part_id')
            ->selectRaw('part_id, -sum(quantity) as used')
            ->pluck('used', 'part_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->filter(fn (int $quantity) => $quantity > 0);

        // How each part left stock for this ticket: used up, lent, put in as a spare (may be several).
        $types = StockMovement::query()
            ->where('ticket_id', $ticketId)
            ->whereIn('type', StockMovement::OUT_TYPES)
            ->distinct()
            ->get(['part_id', 'type'])
            ->groupBy('part_id')
            ->map(fn ($rows) => array_values(array_intersect(StockMovement::OUT_TYPES, $rows->pluck('type')->all())));

        $units = PartUnit::query()->where('ticket_id', $ticketId)->where('status', PartUnit::STATUS_ISSUED)
            ->orderBy('id')->get(['id', 'part_id', 'serial_number'])->groupBy('part_id');

        return Part::withTrashed()
            ->whereKey($used->keys())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit', 'track_serial'])
            ->map(fn (Part $part) => [
                'part_id' => $part->id,
                ...$part->only(['code', 'name', 'unit', 'track_serial']),
                'quantity' => (int) $used[$part->id],
                'types' => $types[$part->id] ?? [],
                'units' => ($units[$part->id] ?? collect())->map(fn (PartUnit $unit) => $unit->only(['id', 'serial_number']))->values()->all(),
            ])
            ->all();
    }
}
