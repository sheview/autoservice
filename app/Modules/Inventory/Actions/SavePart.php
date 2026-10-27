<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Asset\Actions\CategoryOptions;
use App\Modules\Inventory\Models\Part;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a part of the current tenant. Stock is not touched here (RecordStockMovement).
 * A new part with no code gets the next free one ("PT-00001", GeneratePartCode).
 * A new part is followed by serial number when asked, else as its category (an asset category,
 * requires_serial) says; an existing
 * part changes that only through StartTrackingSerials / StopTrackingSerials.
 */
class SavePart
{
    public function __construct(private GeneratePartCode $generateCode, private CategoryOptions $categories) {}

    /**
     * @param  array{code?: string|null, name: string, unit: string, part_number?: string|null, brand?: string|null,
     *     min_qty?: int|null, unit_cost?: int|null, is_active?: bool, notes?: string|null, asset_category_id?: int|null, storage_location_id?: int|null,
     *     track_serial?: bool|null}  $data  unit_cost in satang
     */
    public function handle(?Part $part, array $data): Part
    {
        // The code lock lasts until the part is saved.
        return DB::transaction(fn () => $this->save($part, $data));
    }

    private function save(?Part $part, array $data): Part
    {
        if ($part === null) {
            if (blank($data['code'] ?? null)) {
                $data['code'] = $this->generateCode->handle();
            }
            $part = new Part;
            $part->track_serial = isset($data['track_serial'])
                ? (bool) $data['track_serial']
                : (bool) (collect($this->categories->handle())->firstWhere('id', (int) ($data['asset_category_id'] ?? 0))['requires_serial'] ?? false);
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
