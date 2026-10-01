<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a request that has not been decided yet.
 */
class CancelCheckout
{
    public function handle(AssetCheckout $checkout, User $actor): AssetCheckout
    {
        if ($checkout->status !== AssetCheckout::STATUS_PENDING) {
            throw ValidationException::withMessages(['checkout' => __('asset.checkouts.not_pending')]);
        }

        $checkout->update(['status' => AssetCheckout::STATUS_CANCELLED]);

        activity()->performedOn($checkout->asset)->causedBy($actor)->event('checkout_cancelled')
            ->withProperties(['checkout_no' => $checkout->checkout_no])
            ->log('ยกเลิกคำขอเบิก/ยืม');

        return $checkout;
    }
}
