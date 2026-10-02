<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands what a purchase brought (Inventory module, PurchaseIssueLines) to whoever asked for it, in
 * one go: an issue request for them with those lines, approved at once (bought on an approved
 * purchase, SubmitCheckoutRequest) and handed out in full, so its papers can be printed. For the
 * project of the purchase, and the ticket of the request it was asked from (parts need one).
 * All or nothing.
 */
class HandOutPurchase
{
    public function __construct(
        private SaveCheckoutRequest $save,
        private SubmitCheckoutRequest $submit,
        private FulfillCheckoutItem $fulfill,
    ) {}

    /**
     * @param  array{id: int, pr_no: string, requested_by: int|null, contract_id: int|null, checkout_request_id: int|null,
     *     lines: list<array{item_type: string, id: int, qty: int}>}  $purchase  from PurchaseIssueLines
     */
    public function handle(array $purchase, User $actor): CheckoutRequest
    {
        if ($purchase['lines'] === []) {
            throw ValidationException::withMessages(['hand_out' => __('asset.requests.nothing_to_hand_out')]);
        }
        $source = $purchase['checkout_request_id'] ? CheckoutRequest::query()->find($purchase['checkout_request_id']) : null;
        $ticketId = $source?->ticket_id;
        if ($ticketId === null && collect($purchase['lines'])->contains('item_type', CheckoutItem::TYPE_PART)) {
            throw ValidationException::withMessages(['hand_out' => __('asset.requests.hand_out_needs_ticket')]);
        }
        $borrower = $purchase['requested_by'] ? User::query()->find($purchase['requested_by']) : null;

        return DB::transaction(function () use ($purchase, $actor, $source, $ticketId, $borrower) {
            $checkout = $this->save->handle(null, [
                'borrower_user_id' => $borrower?->id,
                'borrower_name' => $borrower ? null : ($source?->borrower_name ?? $actor->name),
                'borrower_department' => $source?->borrower_department,
                'borrower_phone' => $source?->borrower_phone,
                'ticket_id' => $ticketId,
                'contract_id' => $purchase['contract_id'],
                'purpose' => __('asset.requests.hand_out_purpose', ['no' => $purchase['pr_no']]).($source ? " ({$source->request_no})" : ''),
                'items' => collect($purchase['lines'])->map(fn (array $line) => [
                    'item_type' => $line['item_type'],
                    'asset_id' => $line['item_type'] === CheckoutItem::TYPE_ASSET ? $line['id'] : null,
                    'part_id' => $line['item_type'] === CheckoutItem::TYPE_PART ? $line['id'] : null,
                    'checkout_type' => CheckoutItem::ISSUE,
                    'qty' => $line['qty'],
                    'purchase_request_id' => $purchase['id'],
                ])->all(),
            ], $actor);

            $checkout = $this->submit->handle($checkout, $actor);
            if ($checkout->status !== CheckoutRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['hand_out' => __('asset.requests.nothing_to_hand_out')]);
            }
            foreach ($checkout->items()->get() as $item) {
                $this->fulfill->handle($item, $item->remaining(), $actor);
            }

            return $checkout->refresh();
        });
    }
}
