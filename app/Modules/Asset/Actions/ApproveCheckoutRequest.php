<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approver's decision on a waiting request, in one go: every line as asked, unless the
 * decision for a line lowers its quantity or rejects it (a quantity of 0 or a reason) — a rejected
 * line always says why (e.g. nothing in stock). If every line ends up rejected, so is the request.
 */
class ApproveCheckoutRequest
{
    /**
     * @param  array<int|string, array{qty?: int|null, reject_reason?: string|null}>  $lines  item id => decision
     */
    public function handle(CheckoutRequest $request, User $actor, array $lines = []): CheckoutRequest
    {
        $this->ensurePending($request);

        return DB::transaction(function () use ($request, $actor, $lines) {
            $approved = 0;
            foreach ($request->items()->get() as $item) {
                $decision = $lines[$item->id] ?? [];
                $qty = array_key_exists('qty', $decision) && $decision['qty'] !== null ? (int) $decision['qty'] : $item->qty_requested;
                $reason = trim((string) ($decision['reject_reason'] ?? ''));

                if ($qty > $item->qty_requested || $qty < 0) {
                    throw ValidationException::withMessages(["lines.{$item->id}.qty" => __('asset.requests.qty_over_requested')]);
                }
                if ($qty === 0 || $reason !== '') {
                    if ($reason === '') {
                        throw ValidationException::withMessages(["lines.{$item->id}.reject_reason" => __('asset.requests.reason_required')]);
                    }
                    $item->update(['status' => CheckoutItem::STATUS_REJECTED, 'qty_approved' => 0, 'reject_reason' => $reason]);

                    continue;
                }

                $item->update(['status' => CheckoutItem::STATUS_APPROVED, 'qty_approved' => $qty]);
                $approved++;
            }

            $request->update([
                'status' => $approved > 0 ? CheckoutRequest::STATUS_APPROVED : CheckoutRequest::STATUS_REJECTED,
                'approved_by' => $actor->id,
                'approved_by_name' => $actor->name,
                'approved_at' => now(),
                'reject_reason' => $approved > 0 ? null : __('asset.requests.all_lines_rejected'),
            ]);
            CheckoutStatus::refresh($request);

            activity()->performedOn($request)->causedBy($actor)->event($approved > 0 ? 'checkout_request_approved' : 'checkout_request_rejected')
                ->withProperties(['request_no' => $request->request_no, 'lines' => $request->items()->get(['id', 'item_name', 'qty_requested', 'qty_approved', 'status', 'reject_reason'])->toArray()])
                ->log(($approved > 0 ? 'อนุมัติใบเบิก/ยืม ' : 'ไม่อนุมัติใบเบิก/ยืม ').$request->request_no);

            RequestAlert::send($approved > 0 ? 'checkout_approved' : 'checkout_rejected', $request, $actor->name, $request->reject_reason);

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
