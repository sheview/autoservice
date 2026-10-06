<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;

/**
 * The status of an approved request follows its lines: every line finished (handed out in full,
 * rejected or cancelled) = fulfilled; some handed out = partial; otherwise approved. A closed,
 * rejected or cancelled request, or one not decided yet, is left as it is.
 */
class CheckoutStatus
{
    public static function refresh(CheckoutRequest $request): CheckoutRequest
    {
        if (! in_array($request->status, [CheckoutRequest::STATUS_APPROVED, CheckoutRequest::STATUS_PARTIAL, CheckoutRequest::STATUS_FULFILLED], true)) {
            return $request;
        }

        $items = $request->items()->get();
        $finished = $items->every(fn (CheckoutItem $item) => in_array($item->status, CheckoutItem::FINISHED, true));
        $anyOut = $items->contains(fn (CheckoutItem $item) => $item->qty_fulfilled > 0);

        $request->update(['status' => match (true) {
            $finished => CheckoutRequest::STATUS_FULFILLED,
            $anyOut => CheckoutRequest::STATUS_PARTIAL,
            default => CheckoutRequest::STATUS_APPROVED,
        }]);

        return $request;
    }

    /**
     * Whether the request is done with and so closed on its own: closable, and nothing lent is
     * still out (a loan stays open, and on the list, until it is back).
     */
    public static function settled(CheckoutRequest $request): bool
    {
        return self::closable($request) && $request->items()->get()
            ->doesntContain(fn (CheckoutItem $item) => $item->checkout_type === CheckoutItem::LOAN && $item->outstanding() > 0);
    }

    /** Whether the request may be closed: no line still waits for a decision or a hand-out. */
    public static function closable(CheckoutRequest $request): bool
    {
        return $request->status === CheckoutRequest::STATUS_FULFILLED
            && $request->items()->whereNotIn('status', CheckoutItem::FINISHED)->doesntExist();
    }
}
