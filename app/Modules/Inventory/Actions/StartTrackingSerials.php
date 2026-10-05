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
 * Starts following a part by serial number. Every piece now on hand needs its serial first:
 * the pieces kept from an earlier time it was tracked (those still really here) plus the
 * serials typed now must be exactly the stock. Kept pieces not found any more are taken off
 * ("removed", with the reason); the stock itself does not change.
 */
class StartTrackingSerials
{
    /**
     * @param  list<int>  $keepIds  pieces still "in stock" from before that are really here
     * @param  list<string>  $serials  serials of the other pieces on hand (PartSerials::clean)
     */
    public function handle(Part $part, array $keepIds, array $serials, User $actor): Part
    {
        return DB::transaction(function () use ($part, $keepIds, $serials, $actor) {
            $locked = Part::query()->lockForUpdate()->findOrFail($part->id);
            if ($locked->track_serial) {
                throw ValidationException::withMessages(['serials' => __('inventory.units.already_tracked')]);
            }

            $before = $locked->units()->where('status', PartUnit::STATUS_IN_STOCK)->lockForUpdate()->get();
            $kept = $before->whereIn('id', array_map('intval', $keepIds));
            PartSerials::check($locked, $serials, $locked->qty_on_hand - $kept->count());

            $event = fn (PartUnit $unit, string $action, ?string $from, string $to, ?string $reason = null) => PartUnitEvent::create([
                'part_unit_id' => $unit->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
                'serial_number' => $unit->serial_number, 'reason' => $reason, 'user_id' => $actor->id, 'user_name' => $actor->name,
            ]);

            foreach ($before->diff($kept) as $gone) {
                $gone->update(['status' => PartUnit::STATUS_REMOVED]);
                $event($gone, PartUnitEvent::ACTION_REMOVE, PartUnit::STATUS_IN_STOCK, PartUnit::STATUS_REMOVED, __('inventory.units.not_found_on_start'));
            }
            foreach ($serials as $serial) {
                $unit = $locked->units()->create([
                    'serial_number' => $serial,
                    'unit_cost' => $locked->unit_cost,
                    'received_on' => now()->toDateString(),
                    'source' => PartUnit::SOURCE_BACKFILL,
                ]);
                $event($unit, PartUnitEvent::ACTION_BACKFILL, null, PartUnit::STATUS_IN_STOCK);
            }

            $locked->forceFill(['track_serial' => true])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('part_tracking_started')
                ->withProperties(['code' => $locked->code, 'serials' => count($serials), 'kept' => $kept->count(), 'removed' => $before->count() - $kept->count()])
                ->log(__('inventory.units.log.started', ['code' => $locked->code]));

            return $locked;
        });
    }
}
