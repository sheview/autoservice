<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnitEvent;

/**
 * The serial numbers that went out on issue/loan request lines (Asset module), as they were
 * written when handed out (a later correction of a piece does not change them), and those taken
 * back since: what the papers print. "revisions" counts the take-backs after the hand-over, each
 * making a corrected issue of the papers.
 */
class IssuedPartSerials
{
    /**
     * @param  list<int>  $checkoutItemIds
     * @return array<int, array{out: list<array{unit_id: int, serial: string, at: string}>, returned: list<array{unit_id: int, serial: string, reason: string|null, by: string|null, at: string}>, revisions: int}> keyed by line id
     */
    public function handle(array $checkoutItemIds): array
    {
        $ids = array_values(array_unique(array_filter($checkoutItemIds)));
        if ($ids === []) {
            return [];
        }

        return PartUnitEvent::query()
            ->whereIn('checkout_item_id', $ids)
            ->whereIn('action', [PartUnitEvent::ACTION_ISSUE, PartUnitEvent::ACTION_RETURN])
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->groupBy('checkout_item_id')
            ->map(function ($events) {
                $returns = $events->where('action', PartUnitEvent::ACTION_RETURN);

                return [
                    'out' => $events->where('action', PartUnitEvent::ACTION_ISSUE)->map(fn (PartUnitEvent $event) => [
                        'unit_id' => $event->part_unit_id, 'serial' => $event->serial_number, 'at' => $event->created_at->toIso8601String(),
                    ])->values()->all(),
                    'returned' => $returns->map(fn (PartUnitEvent $event) => [
                        'unit_id' => $event->part_unit_id, 'serial' => $event->serial_number, 'reason' => $event->reason,
                        'by' => $event->user_name, 'at' => $event->created_at->toIso8601String(),
                    ])->values()->all(),
                    'revisions' => $returns->pluck('stock_movement_id')->unique()->count(),
                ];
            })
            ->all();
    }
}
