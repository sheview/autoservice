<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSerials;
use Illuminate\Validation\ValidationException;

/**
 * Receives pieces of a part tracked by serial number: one piece per serial, all into stock in
 * one movement. A serial the part already has (or given twice) refuses the whole receipt.
 */
class ReceivePartUnits
{
    public function __construct(private RecordStockMovement $recordMovement) {}

    /**
     * @param  list<string>  $serials  cleaned (PartSerials::clean)
     * @param  array{unit_cost?: int|null, reference?: string|null, note?: string|null, source?: string,
     *     purchase_receipt_id?: int|null, supplier?: string|null, warranty_until?: string|null, received_on?: string|null}  $details
     */
    public function handle(Part $part, array $serials, ?User $actor, array $details = []): StockMovement
    {
        if ($serials === []) {
            throw ValidationException::withMessages(['serials' => __('inventory.units.serials_required')]);
        }

        return $this->recordMovement->handle($part, StockMovement::TYPE_RECEIVE, count($serials), $actor, [
            'unit_cost' => $details['unit_cost'] ?? null,
            'reference' => $details['reference'] ?? null,
            'note' => $details['note'] ?? null,
            'units' => function (Part $locked) use ($serials, $details) {
                // Again with the part locked: two receipts of the same serial cannot both pass.
                PartSerials::check($locked, $serials);

                return array_map(function (string $serial) use ($locked, $details) {
                    $unit = $locked->units()->create([
                        'serial_number' => $serial,
                        'unit_cost' => $details['unit_cost'] ?? $locked->unit_cost,
                        'received_on' => $details['received_on'] ?? now()->toDateString(),
                        'source' => $details['source'] ?? PartUnit::SOURCE_RECEIVE,
                        'purchase_receipt_id' => $details['purchase_receipt_id'] ?? null,
                        'supplier' => $details['supplier'] ?? null,
                        'warranty_until' => $details['warranty_until'] ?? null,
                    ]);

                    return [
                        'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_RECEIVE, 'from_status' => null,
                        'to_status' => PartUnit::STATUS_IN_STOCK, 'serial_number' => $serial, 'reason' => $details['note'] ?? null,
                    ];
                }, $serials);
            },
        ]);
    }
}
