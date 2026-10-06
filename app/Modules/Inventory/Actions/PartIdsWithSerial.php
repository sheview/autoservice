<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnit;

/**
 * Ids of the parts having a piece whose serial number contains the text: the part list finds
 * parts by serial.
 */
class PartIdsWithSerial
{
    /**
     * @return list<int>
     */
    public function handle(string $search): array
    {
        $search = trim($search);

        return mb_strlen($search) < 2 ? [] : PartUnit::query()
            ->where('serial_number', 'like', '%'.addcslashes($search, '%_\\').'%')
            ->distinct()->pluck('part_id')->map(fn ($id) => (int) $id)->all();
    }
}
