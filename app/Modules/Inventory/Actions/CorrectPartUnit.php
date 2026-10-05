<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Support\PartSerials;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a piece's serial number or warranty after the fact, with the reason and who did it
 * (its history and the activity log). Documents already issued keep the serial they printed.
 */
class CorrectPartUnit
{
    /**
     * @param  array{serial_number: string, warranty_until?: string|null}  $data
     */
    public function handle(PartUnit $unit, array $data, string $reason, User $actor): PartUnit
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('inventory.units.reason_required')]);
        }

        return DB::transaction(function () use ($unit, $data, $reason, $actor) {
            $part = Part::withTrashed()->lockForUpdate()->findOrFail($unit->part_id);
            $serial = PartSerials::clean($data['serial_number'])[0] ?? '';
            if ($serial === '') {
                throw ValidationException::withMessages(['serial_number' => __('inventory.units.serials_required')]);
            }
            PartSerials::check($part, [$serial], field: 'serial_number', except: $unit->id);

            $old = $unit->only(['serial_number', 'warranty_until']);
            $unit->fill(['serial_number' => $serial, 'warranty_until' => $data['warranty_until'] ?? null]);
            if (! $unit->isDirty()) {
                throw ValidationException::withMessages(['serial_number' => __('inventory.units.unchanged')]);
            }
            $unit->save();

            PartUnitEvent::create([
                'part_unit_id' => $unit->id, 'action' => PartUnitEvent::ACTION_CORRECT, 'from_status' => $unit->status, 'to_status' => $unit->status,
                'serial_number' => $serial, 'reason' => __('inventory.units.corrected_from', ['serial' => $old['serial_number']]).' · '.$reason,
                'user_id' => $actor->id, 'user_name' => $actor->name,
            ]);
            activity()->performedOn($part)->causedBy($actor)->event('part_unit_corrected')
                ->withProperties(['code' => $part->code, 'old' => $old['serial_number'], 'new' => $serial, 'warranty_until' => $data['warranty_until'] ?? null, 'reason' => $reason])
                ->log(__('inventory.units.log.corrected', ['code' => $part->code, 'old' => $old['serial_number'], 'new' => $serial]));

            return $unit;
        });
    }
}
