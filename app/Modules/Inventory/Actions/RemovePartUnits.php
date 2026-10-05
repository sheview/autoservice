<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Takes chosen pieces in stock off as broken ("removed"): an adjustment of the stock, with the
 * reason and who did it in the ledger and the activity log. The pieces and their history stay.
 */
class RemovePartUnits
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private PickPartUnits $pick,
    ) {}

    /**
     * @param  list<int>  $unitIds
     */
    public function handle(Part $part, array $unitIds, User $actor, string $reason, ?string $reference = null): StockMovement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['note' => __('inventory.units.reason_required')]);
        }
        $serials = [];

        $movement = $this->recordMovement->handle($part, StockMovement::TYPE_ADJUST, 0, $actor, [
            'reference' => $reference,
            'note' => $reason,
            'units' => function (Part $locked) use ($unitIds, $reason, &$serials) {
                return $this->pick->handle($locked, $unitIds, PartUnit::STATUS_IN_STOCK)->map(function (PartUnit $unit) use ($reason, &$serials) {
                    $unit->update(['status' => PartUnit::STATUS_REMOVED]);
                    $serials[] = $unit->serial_number;

                    return [
                        'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_REMOVE, 'from_status' => PartUnit::STATUS_IN_STOCK,
                        'to_status' => PartUnit::STATUS_REMOVED, 'serial_number' => $unit->serial_number, 'reason' => $reason,
                    ];
                })->all();
            },
        ]);

        activity()->performedOn($part)->causedBy($actor)->event('part_units_removed')
            ->withProperties(['code' => $part->code, 'serials' => $serials, 'reason' => $reason])
            ->log(__('inventory.units.log.removed', ['code' => $part->code, 'serials' => implode(', ', $serials)]));

        return $movement;
    }
}
