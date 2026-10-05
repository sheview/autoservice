<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;

/**
 * Takes chosen pieces of a tracked part back into stock: only pieces issued, and (when given)
 * only those of that ticket or request line. The reason and who took them back are kept.
 */
class ReturnPartUnits
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private PickPartUnits $pick,
    ) {}

    /**
     * @param  list<int>  $unitIds
     * @param  array{ticket_id?: int|null, checkout_item_id?: int|null, reference?: string|null, note?: string|null}  $details
     */
    public function handle(Part $part, array $unitIds, User $actor, array $details = []): StockMovement
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

        return $this->recordMovement->handle($part, StockMovement::TYPE_RETURN, max(count($unitIds), 1), $actor, [
            'ticket_id' => $details['ticket_id'] ?? null,
            'reference' => $details['reference'] ?? null,
            'note' => $details['note'] ?? null,
            'units' => function (Part $locked) use ($unitIds, $details) {
                $heldBy = ['ticket_id' => $details['ticket_id'] ?? null, 'checkout_item_id' => $details['checkout_item_id'] ?? null];

                return $this->pick->handle($locked, $unitIds, PartUnit::STATUS_ISSUED, $heldBy)->map(function (PartUnit $unit) use ($details) {
                    // Where it comes back from, for its history.
                    $from = ['ticket_id' => $unit->ticket_id, 'asset_id' => $unit->asset_id, 'checkout_item_id' => $unit->checkout_item_id];
                    $unit->update(['status' => PartUnit::STATUS_IN_STOCK, 'ticket_id' => null, 'asset_id' => null, 'checkout_item_id' => null]);

                    return [
                        'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_RETURN, 'from_status' => PartUnit::STATUS_ISSUED,
                        'to_status' => PartUnit::STATUS_IN_STOCK, 'serial_number' => $unit->serial_number, 'reason' => $details['note'] ?? null,
                    ] + $from;
                })->all();
            },
        ]);
    }
}
