<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * Creates or updates a part of the current tenant. Stock is not touched here (RecordStockMovement).
 */
class SavePart
{
    /**
     * @param  array{code: string, name: string, unit: string, part_number?: string|null, brand?: string|null,
     *     min_qty?: int|null, unit_cost?: int|null, is_active?: bool, notes?: string|null}  $data  unit_cost in satang
     */
    public function handle(?Part $part, array $data): Part
    {
        $part ??= new Part;
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
