<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;

/**
 * Parts used on a ticket: what was issued to it minus what came back, per part.
 * For the Service module, which must not use the Inventory models directly.
 */
class TicketParts
{
    /**
     * @return list<array{part_id: int, code: string, name: string, unit: string, quantity: int, types: list<string>}>
     */
    public function handle(int $ticketId): array
    {
        $used = StockMovement::query()
            ->where('ticket_id', $ticketId)
            ->groupBy('part_id')
            ->selectRaw('part_id, -sum(quantity) as used')
            ->pluck('used', 'part_id')
            ->filter(fn ($quantity) => (int) $quantity > 0);

        // How each part left stock for this ticket: used up, lent, put in as a spare (may be several).
        $types = StockMovement::query()
            ->where('ticket_id', $ticketId)
            ->whereIn('type', StockMovement::OUT_TYPES)
            ->distinct()
            ->get(['part_id', 'type'])
            ->groupBy('part_id')
            ->map(fn ($rows) => array_values(array_intersect(StockMovement::OUT_TYPES, $rows->pluck('type')->all())));

        return Part::withTrashed()
            ->whereKey($used->keys())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit'])
            ->map(fn (Part $part) => [
                'part_id' => $part->id,
                ...$part->only(['code', 'name', 'unit']),
                'quantity' => (int) $used[$part->id],
                'types' => $types[$part->id] ?? [],
            ])
            ->all();
    }
}
