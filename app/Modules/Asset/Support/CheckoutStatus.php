<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\CheckoutFulfillment;
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

    /**
     * Whether the request may be closed: no line still waits for a decision or a hand-out, and the
     * receiver has confirmed every hand-out (a job is done once the things have arrived, not when
     * they left the store). A request with nobody here to confirm (asked by another company
     * through a share) is done once it is all handed out.
     */
    public static function closable(CheckoutRequest $request): bool
    {
        return $request->status === CheckoutRequest::STATUS_FULFILLED
            && $request->items()->whereNotIn('status', CheckoutItem::FINISHED)->doesntExist()
            && (! self::hasReceiver($request) || ! self::awaitingReceipt($request));
    }

    /** Someone of this company confirms receipt (CheckoutRequestPolicy::confirmReceipt): the borrower, else who asked. */
    public static function hasReceiver(CheckoutRequest $request): bool
    {
        return ($request->borrower_user_id ?? $request->requester_id) !== null;
    }

    /** Something handed out the receiver has not confirmed yet (reported as wrong or not). */
    public static function awaitingReceipt(CheckoutRequest $request): bool
    {
        return CheckoutFulfillment::query()
            ->whereIn('item_id', $request->items()->select('id'))
            ->whereNull('received_at')
            ->exists();
    }
}
