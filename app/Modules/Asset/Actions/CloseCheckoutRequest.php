<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Closes a request once every line is finished (handed out in full, rejected or cancelled): never
 * while a line still waits for a decision, is partly handed out or backordered. Lent assets are
 * still followed up line by line until they come back.
 */
class CloseCheckoutRequest
{
    public function handle(CheckoutRequest $request, User $actor): CheckoutRequest
    {
        if (! CheckoutStatus::closable($request)) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_closable')]);
        }

        $request->update(['status' => CheckoutRequest::STATUS_CLOSED, 'closed_at' => now()]);

        activity()->performedOn($request)->causedBy($actor)->event('checkout_request_closed')
            ->withProperties(['request_no' => $request->request_no])
            ->log('ปิดใบเบิก/ยืม '.$request->request_no);

        return $request;
    }
}
