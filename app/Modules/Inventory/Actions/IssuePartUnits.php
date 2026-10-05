<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Takes chosen pieces of a tracked part out of stock (used, lent or put in as a spare), each tied
 * to where it went: the ticket, the device it was put into, the issue/loan request line. Only
 * pieces in stock can go.
 */
class IssuePartUnits
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private PickPartUnits $pick,
    ) {}

    /**
     * @param  list<int>  $unitIds
     * @param  array{ticket_id?: int|null, asset_id?: int|null, checkout_item_id?: int|null, checkout_fulfillment_id?: int|null,
     *     reference?: string|null, note?: string|null}  $details
     */
    public function handle(Part $part, array $unitIds, User $actor, string $type = StockMovement::TYPE_ISSUE, array $details = []): StockMovement
    {
        if (! in_array($type, StockMovement::OUT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('validation.in', ['attribute' => __('inventory.fields.type')])]);
        }
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

        return $this->recordMovement->handle($part, $type, max(count($unitIds), 1), $actor, [
            'ticket_id' => $details['ticket_id'] ?? null,
            'reference' => $details['reference'] ?? null,
            'note' => $details['note'] ?? null,
            'units' => function (Part $locked) use ($unitIds, $details) {
                $where = [
                    'ticket_id' => $details['ticket_id'] ?? null,
                    'asset_id' => $details['asset_id'] ?? null,
                    'checkout_item_id' => $details['checkout_item_id'] ?? null,
                ];

                return $this->pick->handle($locked, $unitIds, PartUnit::STATUS_IN_STOCK)->map(function (PartUnit $unit) use ($where, $details) {
                    $unit->update(['status' => PartUnit::STATUS_ISSUED] + $where);

                    return [
                        'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_ISSUE, 'from_status' => PartUnit::STATUS_IN_STOCK,
                        'to_status' => PartUnit::STATUS_ISSUED, 'serial_number' => $unit->serial_number,
                        'checkout_fulfillment_id' => $details['checkout_fulfillment_id'] ?? null, 'reason' => $details['note'] ?? null,
                    ] + $where;
                })->all();
            },
        ]);
    }
}
