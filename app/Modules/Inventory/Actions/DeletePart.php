<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a part that has no stock left. Its movements stay in the ledger.
 */
class DeletePart
{
    public function handle(Part $part): void
    {
        if ($part->qty_on_hand > 0) {
            throw ValidationException::withMessages(['part' => __('inventory.parts.in_stock')]);
        }

        $part->delete();
    }
}
