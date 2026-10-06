<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PartsForCheckout;
use Illuminate\Validation\ValidationException;

/**
 * "Hand out all": every line still to hand out gets what is left of it (FulfillCheckoutItem, one
 * line at a time), a part no more than is on hand. Left for the line's own form: a part followed
 * by serial number (its pieces are picked), a part out of stock and an asset that is not
 * available. Returns how many lines were handed out and how many were left.
 */
class FulfillCheckoutRequest
{
    public function __construct(
        private FulfillCheckoutItem $fulfill,
        private PartsForCheckout $parts,
    ) {}

    /**
     * @return array{done: int, left: int}
     */
    public function handle(CheckoutRequest $request, User $actor): array
    {
        if (! in_array($request->status, [CheckoutRequest::STATUS_APPROVED, CheckoutRequest::STATUS_PARTIAL], true)) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_to_fulfill')]);
        }

        $items = $request->items()->whereIn('status', CheckoutItem::TO_FULFILL)->orderBy('id')->get()
            ->filter(fn (CheckoutItem $item) => $item->remaining() > 0);
        $parts = $this->parts->handle($items->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->map(fn ($id) => (int) $id)->all());

        $done = 0;
        $left = 0;
        foreach ($items as $item) {
            $qty = $item->remaining();
            if ($item->item_type === CheckoutItem::TYPE_PART) {
                $part = $parts[$item->part_id] ?? null;
                $qty = $part === null || $part['track_serial'] ? 0 : min($qty, $part['qty_on_hand']);
            }
            if ($qty < 1) {
                $left++;

                continue;
            }

            try {
                // The line's request is the one being handed out (and its status kept up to date).
                $this->fulfill->handle($item->setRelation('request', $request), $qty, $actor);
                $done++;
                if ($item->part_id !== null && isset($parts[$item->part_id])) {
                    $parts[$item->part_id]['qty_on_hand'] -= $qty;
                }
            } catch (ValidationException) {
                // Not available after all (e.g. an asset now in use): left for its own form.
                $left++;
            }
        }

        if ($done === 0) {
            throw ValidationException::withMessages(['request' => __('asset.requests.nothing_to_fulfill')]);
        }

        return ['done' => $done, 'left' => $left];
    }
}
