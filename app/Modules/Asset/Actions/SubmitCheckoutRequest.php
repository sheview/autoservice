<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutRules;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ApprovedPurchases;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for approval. It is approved at once in full when every line needs no decision:
 * a line handing out what an approved purchase request bought (no more than it brought; it was
 * approved when it was asked to buy), or a part worth no more than the company's auto-approval
 * limit (CheckoutRules). Anything else waits for an approver.
 */
class SubmitCheckoutRequest
{
    public function __construct(
        private PartsForCheckout $parts,
        private TenantContext $context,
        private Modules $modules,
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

    /** Every line bought on an approved purchase, or a part priced and worth at most the limit. */
    private function autoApproves(CheckoutRequest $request): bool
    {
        $limit = CheckoutRules::autoApproveLimit($this->context->tenant());
        $items = $request->items()->get();
        if ($items->isEmpty()) {
            return false;
        }

        $purchases = $this->modules->enabled('inventory') ? app(ApprovedPurchases::class)->handle($items->pluck('purchase_request_id')->all()) : [];
        $partIds = $items->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->all();
        $parts = $partIds === [] ? [] : $this->parts->handle($partIds);
        // What each purchase still has to hand out, used up line by line.
        $left = $purchases;

        return $items->every(function (CheckoutItem $item) use ($parts, $limit, &$left) {
            if ($item->purchase_request_id !== null && ($left[$item->purchase_request_id] ?? 0) >= $item->qty_requested) {
                $left[$item->purchase_request_id] -= $item->qty_requested;

                return true;
            }
            $cost = $item->item_type === CheckoutItem::TYPE_PART ? ($parts[$item->part_id]['unit_cost'] ?? null) : null;

            return $limit !== null && $cost !== null && $cost * $item->qty_requested <= $limit;
        });
    }
}
