<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a request before anything is decided (a draft, or waiting for approval): what it asked
 * for is free again.
 */
class CancelCheckoutRequest
{
    public function handle(CheckoutRequest $request, User $actor): CheckoutRequest
    {
        if (! in_array($request->status, [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_PENDING], true)) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_pending')]);
        }

        return DB::transaction(function () use ($request, $actor) {
            $request->items()->update(['status' => CheckoutItem::STATUS_CANCELLED]);
            $request->update(['status' => CheckoutRequest::STATUS_CANCELLED]);

            activity()->performedOn($request)->causedBy($actor)->event('checkout_request_cancelled')
                ->withProperties(['request_no' => $request->request_no])
                ->log('ยกเลิกใบเบิก/ยืม '.$request->request_no);

            return $request;
        });
    }
}
