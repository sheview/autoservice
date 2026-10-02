<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\SavePurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What cannot be handed out now waits as "backordered" (never dropped silently). With $order,
 * a purchase request for the missing quantity is opened at once and tied to the line, so the
 * buyers see what it is for; when the stock comes in the line is ready again (RestockBackorders).
 */
class BackorderCheckoutItem
{
    public function __construct(private SavePurchaseRequest $savePurchase) {}

    public function handle(CheckoutItem $item, User $actor, bool $order = false): CheckoutItem
    {
        if (! in_array($item->status, CheckoutItem::TO_FULFILL, true) || $item->remaining() === 0) {
            throw ValidationException::withMessages(['item' => __('asset.requests.not_to_fulfill')]);
        }
        if ($order && $item->purchase_request_id !== null) {
            throw ValidationException::withMessages(['item' => __('asset.requests.already_ordered')]);
        }

        return DB::transaction(function () use ($item, $actor, $order) {
            $request = $item->request;
            $item->status = CheckoutItem::STATUS_BACKORDERED;

            if ($order) {
                $purchase = $this->savePurchase->handle(null, [
                    'item_name' => $item->item_name,
                    'description' => $item->item_code,
                    'quantity' => $item->remaining(),
                    'unit' => $item->unit ?? __('asset.requests.unit_default'),
                    'reason' => __('asset.requests.backorder_reason', ['no' => $request->request_no, 'borrower' => $request->borrower_name]),
                    'needed_by' => $request->needed_by?->isFuture() ? $request->needed_by->toDateString() : now()->addDays(7)->toDateString(),
                    'contract_id' => $request->contract_id,
                    'links' => [],
                ], $actor);
                $item->purchase_request_id = $purchase->id;
            }

            $item->save();
            CheckoutStatus::refresh($request);

            activity()->performedOn($request)->causedBy($actor)->event('checkout_item_backordered')
                ->withProperties(['request_no' => $request->request_no, 'item' => $item->item_name, 'missing' => $item->remaining(), 'purchase_request_id' => $item->purchase_request_id])
                ->log("ค้างจ่าย {$item->item_name} × {$item->remaining()}");

            return $item;
        });
    }
}
