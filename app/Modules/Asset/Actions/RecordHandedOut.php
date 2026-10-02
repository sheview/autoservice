<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * Records that a new asset is already out with someone (issued or lent before it was registered):
 * a request approved and handed out by the person registering it, on the day it was handed out,
 * with one line for the whole asset. A lent asset comes back on that line like any other.
 */
class RecordHandedOut
{
    public function __construct(private GenerateRequestNumber $generateNumber) {}

    /**
     * @param  array{type: string, borrower_name: string, on: string}  $handedOut  type = CheckoutItem::ISSUE | LOAN
     */
    public function handle(Asset $asset, array $handedOut, User $actor): CheckoutRequest
    {
        $on = CarbonImmutable::parse($handedOut['on'])->startOfDay();
        $quantity = max(1, (int) $asset->quantity);

        $request = CheckoutRequest::create([
            'request_no' => $this->generateNumber->handle(),
            'status' => CheckoutRequest::STATUS_FULFILLED,
            'requester_id' => $actor->id,
            'requester_name' => $actor->name,
            'borrower_name' => $handedOut['borrower_name'],
            'branch_id' => $asset->branch_id,
            'approved_by' => $actor->id,
            'approved_by_name' => $actor->name,
            'approved_at' => $on,
            'submitted_at' => $on,
        ]);

        $item = $request->items()->create([
            'item_type' => CheckoutItem::TYPE_ASSET,
            'asset_id' => $asset->id,
            'item_code' => $asset->asset_code,
            'item_name' => $asset->name,
            'unit' => $asset->unit,
            'checkout_type' => $handedOut['type'] === CheckoutItem::LOAN ? CheckoutItem::LOAN : CheckoutItem::ISSUE,
            'qty_requested' => $quantity,
            'qty_approved' => $quantity,
            'qty_fulfilled' => $quantity,
            'status' => CheckoutItem::STATUS_FULFILLED,
        ]);

        $item->fulfillments()->create([
            'qty' => $quantity,
            'fulfilled_by' => $actor->id,
            'fulfilled_by_name' => $actor->name,
            'fulfilled_at' => $on,
        ]);

        activity()->performedOn($asset)->causedBy($actor)->event('checkout_approved')
            ->withProperties(['request_no' => $request->request_no, 'type' => $item->checkout_type, 'borrower' => $request->borrower_name])
            ->log('บันทึกการเบิก/ยืมตอนเพิ่มทรัพย์สิน');

        return $request;
    }
}
