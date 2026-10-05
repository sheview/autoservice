<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCategory;

/**
 * Creates or updates a part of the current tenant. Stock is not touched here (RecordStockMovement).
 * A new part is followed by serial number when asked, else as its category says; an existing
 * part changes that only through StartTrackingSerials / StopTrackingSerials.
 */
class SavePart
{
    /**
     * @param  array{code: string, name: string, unit: string, part_number?: string|null, brand?: string|null,
     *     min_qty?: int|null, unit_cost?: int|null, is_active?: bool, notes?: string|null, part_category_id?: int|null,
     *     track_serial?: bool|null}  $data  unit_cost in satang
     */
    public function handle(?Part $part, array $data): Part
    {
        if ($part === null) {
            $part = new Part;
            $part->track_serial = isset($data['track_serial'])
                ? (bool) $data['track_serial']
                : (bool) (filled($data['part_category_id'] ?? null) ? PartCategory::query()->find($data['part_category_id'])?->track_serial : false);
        }
        unset($data['track_serial']);
        $data['code'] = strtoupper(trim($data['code']));
        $data['min_qty'] = (int) ($data['min_qty'] ?? 0);
        $part->fill($data);
        if ($part->isDirty('min_qty')) {
            // A new reorder point re-arms the low stock e-mail (NotifyLowStock).
            $part->low_stock_notified_at = null;
        }
        $part->save();

        return $part;
    }
}
