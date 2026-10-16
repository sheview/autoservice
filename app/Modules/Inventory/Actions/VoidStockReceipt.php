<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a receipt entered by mistake: a "void" row takes what it brought back out of stock and
 * points at it (voids_id); the receipt itself stays in the ledger. Only while what came in is
 * still there: as many in stock for a plain part, or every piece it brought still in stock for a
 * part followed by serial number (those pieces are then removed and their serials free again).
 * A receipt of a purchase request is cancelled on the request instead (it counts what came in).
 */
class VoidStockReceipt
{
    public function __construct(private RecordStockMovement $recordMovement) {}

    public function handle(StockMovement $receipt, string $reason, User $actor): StockMovement
    {
        $this->check($receipt, $reason);
        $part = Part::withTrashed()->findOrFail($receipt->part_id);
        $details = [
            'voids_id' => $receipt->id,
            'reference' => $receipt->reference,
            'note' => $reason,
        ];

        if ($part->track_serial) {
            $details['units'] = fn (Part $locked) => $this->removeUnits($receipt, $locked, $reason);
        } elseif ($part->qty_on_hand < $receipt->quantity) {
            throw ValidationException::withMessages(['void' => __('inventory.movements.void.used', [
                'available' => $part->qty_on_hand, 'quantity' => $receipt->quantity, 'unit' => $part->unit,
            ])]);
        }

        try {
            $void = $this->recordMovement->handle($part, StockMovement::TYPE_VOID, $receipt->quantity, $actor, $details);
        } catch (UniqueConstraintViolationException) {
            // Voided by someone else meanwhile.
            throw ValidationException::withMessages(['void' => __('inventory.movements.void.already')]);
        }

        activity()->performedOn($part)->causedBy($actor)->event('stock_receipt_voided')
            ->withProperties(['code' => $part->code, 'receipt_id' => $receipt->id, 'quantity' => $receipt->quantity, 'reason' => $reason])
            ->log(__('inventory.movements.void.log', ['code' => $part->code, 'quantity' => $receipt->quantity]));

        return $void;
    }

    private function check(StockMovement $receipt, string $reason): void
    {
        $refuse = fn (string $key) => throw ValidationException::withMessages(['void' => __("inventory.movements.void.{$key}")]);

        if (trim($reason) === '') {
            $refuse('reason_required');
        }
        if ($receipt->type !== StockMovement::TYPE_RECEIVE) {
            $refuse('not_receipt');
        }
        if (StockMovement::where('voids_id', $receipt->id)->exists()) {
            $refuse('already');
        }
        if (PurchaseReceipt::where('stock_movement_id', $receipt->id)->exists()) {
            $refuse('purchase');
        }
    }

    /**
     * The pieces the receipt brought, all still in stock, taken off and soft-deleted (so their
     * serials can be received again, typed right this time).
     *
     * @return list<array<string, mixed>> part_unit_events
     */
    private function removeUnits(StockMovement $receipt, Part $locked, string $reason): array
    {
        $unitIds = PartUnitEvent::where('stock_movement_id', $receipt->id)->where('action', PartUnitEvent::ACTION_RECEIVE)->pluck('part_unit_id');
        $units = $locked->units()->whereKey($unitIds)->lockForUpdate()->get();

        $gone = $units->where('status', '!=', PartUnit::STATUS_IN_STOCK)->pluck('serial_number');
        if ($units->count() !== $unitIds->count() || $gone->isNotEmpty()) {
            throw ValidationException::withMessages(['void' => __('inventory.movements.void.units_used', ['serials' => $gone->implode(', ') ?: '-'])]);
        }

        return $units->map(function (PartUnit $unit) use ($reason) {
            $unit->update(['status' => PartUnit::STATUS_REMOVED]);
            $unit->delete();

            return [
                'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_REMOVE, 'from_status' => PartUnit::STATUS_IN_STOCK,
                'to_status' => PartUnit::STATUS_REMOVED, 'serial_number' => $unit->serial_number, 'reason' => $reason,
            ];
        })->values()->all();
    }
}
