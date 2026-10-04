<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Events\PurchasedItemHandedOut;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\PartsForCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands out (some of) an approved line. A part comes out of stock against the request's ticket
 * (its cost goes to the job); no more than is on hand. An asset is handed over (a single asset
 * becomes "in use" by the borrower). What is still missing afterwards is backordered when the
 * stock cannot cover it, otherwise the line stays partly handed out.
 */
class FulfillCheckoutItem
{
    public function __construct(
        private IssuePartToTicket $issuePart,
        private PartsForCheckout $parts,
    ) {}

    public function handle(CheckoutItem $item, int $qty, User $actor): CheckoutItem
    {
        $request = $item->request;
        if (! in_array($request->status, [CheckoutRequest::STATUS_APPROVED, CheckoutRequest::STATUS_PARTIAL], true)
            || ! in_array($item->status, CheckoutItem::TO_FULFILL, true)) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.not_to_fulfill')]);
        }
        if ($qty < 1 || $qty > $item->remaining()) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.qty_over_remaining', ['remaining' => $item->remaining()])]);
        }

        return DB::transaction(function () use ($item, $request, $qty, $actor) {
            $movementId = null;

            if ($item->item_type === CheckoutItem::TYPE_PART) {
                // Refuses more than is on hand (the part row is locked meanwhile).
                // A request of another company has no ticket here: the note names the request (and so them).
                $movementId = $this->issuePart->handle($request->ticket_id ? (int) $request->ticket_id : null, (int) $item->part_id, $qty, $actor,
                    __('asset.requests.issued_for', ['no' => $request->request_no]).($request->ticket_id ? '' : ' · '.$request->requester_name))->id;
            } else {
                $asset = Asset::query()->lockForUpdate()->find($item->asset_id);
                if ($asset === null || ! in_array($asset->status, [Asset::STATUS_IN_USE, Asset::STATUS_SPARE], true)) {
                    throw ValidationException::withMessages(['qty' => __('asset.requests.asset_not_available')]);
                }
                if ((int) $asset->quantity <= 1) {
                    $asset->update(['status' => Asset::STATUS_IN_USE, 'used_by' => $request->borrower_name]);
                }
            }

            $item->fulfillments()->create([
                'qty' => $qty,
                'fulfilled_by' => $actor->id,
                'fulfilled_by_name' => $actor->name,
                'fulfilled_at' => now(),
                'stock_movement_id' => $movementId,
            ]);

            $item->qty_fulfilled += $qty;
            $item->status = match (true) {
                $item->remaining() === 0 => CheckoutItem::STATUS_FULFILLED,
                $item->item_type === CheckoutItem::TYPE_PART && $this->onHand($item) < $item->remaining() => CheckoutItem::STATUS_BACKORDERED,
                default => CheckoutItem::STATUS_PARTIAL,
            };
            $item->save();

            CheckoutStatus::refresh($request);
            if ($item->purchase_request_id !== null) {
                // Bought for this line: the purchase request counts it as handed out.
                PurchasedItemHandedOut::dispatch((int) $item->purchase_request_id, $qty, $actor->id);
            }

            activity()->performedOn($request)->causedBy($actor)->event('checkout_item_fulfilled')
                ->withProperties(['request_no' => $request->request_no, 'item' => $item->item_name, 'qty' => $qty, 'status' => $item->status])
                ->log("จ่าย {$item->item_name} × {$qty}");

            return $item;
        });
    }

    private function onHand(CheckoutItem $item): int
    {
        return (int) ($this->parts->handle([(int) $item->part_id])[$item->part_id]['qty_on_hand'] ?? 0);
    }
}
