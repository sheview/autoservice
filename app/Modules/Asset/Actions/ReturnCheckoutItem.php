<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back (some of) an asset handed out on a line, with its condition. A single asset that is
 * back, with nothing else of it out, is spare again. Parts are used up and do not come back.
 */
class ReturnCheckoutItem
{
    public function __construct(private AssetHeldQuantities $held) {}

    public function handle(CheckoutItem $item, User $actor, int $qty, ?string $condition = null): CheckoutItem
    {
        if (! $item->returnable()) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.not_out')]);
        }
        if ($qty < 1 || $qty > $item->outstanding()) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.qty_over_outstanding', ['outstanding' => $item->outstanding()])]);
        }

        return DB::transaction(function () use ($item, $actor, $qty, $condition) {
            $item->qty_returned += $qty;
            if ($item->outstanding() === 0) {
                $item->returned_at = now();
                $item->returned_by_name = $actor->name;
            }
            if (filled($condition)) {
                $item->return_condition = trim($condition);
            }
            $item->save();

            $asset = Asset::query()->find($item->asset_id);
            if ($asset !== null && ($this->held->handle([$asset->id])[$asset->id] ?? 0) === 0 && $asset->status === Asset::STATUS_IN_USE) {
                $asset->update(['status' => Asset::STATUS_SPARE, 'used_by' => null]);
            }

            $request = $item->request;
            activity()->performedOn($request)->causedBy($actor)->event('checkout_item_returned')
                ->withProperties(['request_no' => $request->request_no, 'item' => $item->item_name, 'qty' => $qty, 'condition' => $condition])
                ->log("รับคืน {$item->item_name} × {$qty}");

            RequestAlert::send('checkout_returned', $request, $actor->name, $condition, $item);

            return $item;
        });
    }
}
