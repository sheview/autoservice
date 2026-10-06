<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnitEvent;

/**
 * Ids of the issue/loan request lines (Asset module) a piece went out on whose serial number, as
 * written then, contains the text: the request list finds requests by serial.
 */
class CheckoutItemIdsWithSerial
{
    /**
     * @return list<int>
     */
    public function handle(string $search): array
    {
        $search = trim($search);

        return mb_strlen($search) < 2 ? [] : PartUnitEvent::query()
            ->where('action', PartUnitEvent::ACTION_ISSUE)->whereNotNull('checkout_item_id')
            ->where('serial_number', 'like', '%'.addcslashes($search, '%_\\').'%')
            ->distinct()->pluck('checkout_item_id')->map(fn ($id) => (int) $id)->all();
    }
}
