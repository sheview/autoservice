<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutRules;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for approval. A request of parts only, each line worth no more than the company's
 * auto-approval limit (CheckoutRules), is approved at once in full; anything else waits for an
 * approver.
 */
class SubmitCheckoutRequest
{
    public function __construct(
        private PartsForCheckout $parts,
        private TenantContext $context,
    ) {}

    public function handle(CheckoutRequest $request, User $actor): CheckoutRequest
    {
        if ($request->status !== CheckoutRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_draft')]);
        }

        return DB::transaction(function () use ($request, $actor) {
            $request->update(['status' => CheckoutRequest::STATUS_PENDING, 'submitted_at' => now()]);

            activity()->performedOn($request)->causedBy($actor)->event('checkout_request_submitted')
                ->withProperties(['request_no' => $request->request_no])
                ->log('ส่งใบเบิก/ยืม '.$request->request_no);

            if ($this->autoApproves($request)) {
                $request->items()->update(['status' => CheckoutItem::STATUS_APPROVED, 'qty_approved' => DB::raw('qty_requested')]);
                $request->update([
                    'status' => CheckoutRequest::STATUS_APPROVED,
                    'approved_at' => now(),
                    'approved_by_name' => __('asset.requests.auto_approved'),
                    'auto_approved' => true,
                ]);
                CheckoutStatus::refresh($request);
                RequestAlert::send('checkout_approved', $request, __('asset.requests.auto_approved'));

                return $request->refresh();
            }

            RequestAlert::send('checkout_requested', $request, $actor->name);

            return $request->refresh();
        });
    }

    /** Parts only, each line priced and worth at most the limit. */
    private function autoApproves(CheckoutRequest $request): bool
    {
        $limit = CheckoutRules::autoApproveLimit($this->context->tenant());
        $items = $request->items()->get();
        if ($limit === null || $items->isEmpty() || $items->contains(fn (CheckoutItem $item) => $item->item_type !== CheckoutItem::TYPE_PART)) {
            return false;
        }

        $parts = $this->parts->handle($items->pluck('part_id')->all());

        return $items->every(function (CheckoutItem $item) use ($parts, $limit) {
            $cost = $parts[$item->part_id]['unit_cost'] ?? null;

            return $cost !== null && $cost * $item->qty_requested <= $limit;
        });
    }
}
