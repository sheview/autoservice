<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives up what is left of a line, saying why (the only way a line ends without being handed out
 * in full): what was already handed out stays; nothing is still owed.
 */
class CancelCheckoutItem
{
    public function handle(CheckoutItem $item, User $actor, string $reason): CheckoutItem
    {
        if (! in_array($item->status, CheckoutItem::TO_FULFILL, true)) {
            throw ValidationException::withMessages(['item' => __('asset.requests.not_to_fulfill')]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('asset.requests.reason_required')]);
        }

        return DB::transaction(function () use ($item, $actor, $reason) {
            $missing = $item->remaining();
            $item->update([
                'qty_approved' => $item->qty_fulfilled,
                'status' => $item->qty_fulfilled > 0 ? CheckoutItem::STATUS_FULFILLED : CheckoutItem::STATUS_CANCELLED,
                'reject_reason' => $reason,
            ]);
            CheckoutStatus::refresh($item->request);

            activity()->performedOn($item->request)->causedBy($actor)->event('checkout_item_cancelled')
                ->withProperties(['request_no' => $item->request->request_no, 'item' => $item->item_name, 'cancelled' => $missing, 'reason' => $reason])
                ->log("ยกเลิกส่วนที่เหลือ {$item->item_name} × {$missing}");

            return $item;
        });
    }
}
