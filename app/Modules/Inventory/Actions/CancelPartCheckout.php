<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PartCheckout;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a part request that has not been decided yet; its quantity is free again.
 */
class CancelPartCheckout
{
    public function handle(PartCheckout $checkout, User $actor): PartCheckout
    {
        if ($checkout->status !== PartCheckout::STATUS_PENDING) {
            throw ValidationException::withMessages(['checkout' => __('inventory.part_checkouts.not_pending')]);
        }

        $checkout->update(['status' => PartCheckout::STATUS_CANCELLED]);

        activity()->performedOn($checkout->part)->causedBy($actor)->event('part_checkout_cancelled')
            ->withProperties(['checkout_no' => $checkout->checkout_no])
            ->log('ยกเลิกคำขอเบิก/ยืมอะไหล่');

        return $checkout;
    }
}
