<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approver rejects a whole waiting request, saying why (e.g. nothing in stock).
 */
class RejectCheckoutRequest
{
    public function handle(CheckoutRequest $request, User $actor, string $reason): CheckoutRequest
    {
        $this->ensurePending($request);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reject_reason' => __('asset.requests.reason_required')]);
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request->items()->update(['status' => CheckoutItem::STATUS_REJECTED, 'qty_approved' => 0, 'reject_reason' => $reason]);
            $request->update([
                'status' => CheckoutRequest::STATUS_REJECTED,
                'approved_by' => $actor->id,
                'approved_by_name' => $actor->name,
                'approved_at' => now(),
                'reject_reason' => $reason,
            ]);

            activity()->performedOn($request)->causedBy($actor)->event('checkout_request_rejected')
                ->withProperties(['request_no' => $request->request_no, 'reason' => $reason])
                ->log('ไม่อนุมัติใบเบิก/ยืม '.$request->request_no);

            RequestAlert::send('checkout_rejected', $request, $actor->name, $reason);

            return $request->refresh();
        });
    }

    private function ensurePending(CheckoutRequest $request): void
    {
        if ($request->status !== CheckoutRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_pending')]);
        }
    }
}
