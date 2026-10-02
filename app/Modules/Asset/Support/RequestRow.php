<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;

/**
 * An issue/loan request and its lines as the pages get them (resources/js/types/checkout.ts
 * "CheckoutRequestRow"). Labels from other modules (ticket, project, purchase requests, stock on
 * hand) are passed in, looked up through their actions by the caller.
 */
class RequestRow
{
    /**
     * @param  array<string, mixed>  $extra  e.g. ticket, contract, can
     * @param  array<int, array{ulid: string, pr_no: string, status: string}>  $purchases  by purchase request id
     * @param  array<int, int>  $onHand  part id => quantity on hand
     * @return array<string, mixed>
     */
    public static function of(CheckoutRequest $request, array $extra = [], array $purchases = [], array $onHand = []): array
    {
        return [
            ...$request->only([
                'ulid', 'request_no', 'status', 'requester_id', 'requester_name', 'borrower_user_id', 'borrower_name',
                'borrower_department', 'borrower_phone', 'ticket_id', 'contract_id', 'purpose', 'approved_by_name',
                'auto_approved', 'reject_reason',
            ]),
            'needed_by' => $request->needed_by?->toDateString(),
            'requested_at' => $request->created_at?->toIso8601String(),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'approved_at' => $request->approved_at?->toIso8601String(),
            'closed_at' => $request->closed_at?->toIso8601String(),
            'items' => $request->items->map(fn (CheckoutItem $item) => self::item($item, $purchases, $onHand))->values()->all(),
            ...$extra,
        ];
    }

    /**
     * @param  array<int, array{ulid: string, pr_no: string, status: string}>  $purchases
     * @param  array<int, int>  $onHand
     * @return array<string, mixed>
     */
    public static function item(CheckoutItem $item, array $purchases = [], array $onHand = []): array
    {
        return [
            ...$item->only([
                'id', 'item_type', 'asset_id', 'part_id', 'item_code', 'item_name', 'unit', 'checkout_type',
                'qty_requested', 'qty_approved', 'qty_fulfilled', 'qty_returned', 'status', 'return_condition',
                'returned_by_name', 'reject_reason', 'note', 'purchase_request_id',
            ]),
            'asset_ulid' => $item->item_type === CheckoutItem::TYPE_ASSET ? $item->asset?->ulid : null,
            'due_return_date' => $item->due_return_date?->toDateString(),
            'returned_at' => $item->returned_at?->toIso8601String(),
            'remaining' => $item->remaining(),
            'outstanding' => $item->outstanding(),
            'overdue' => $item->isOverdue(),
            'on_hand' => $item->item_type === CheckoutItem::TYPE_PART ? ($onHand[$item->part_id] ?? null) : null,
            'purchase_request' => $item->purchase_request_id ? ($purchases[$item->purchase_request_id] ?? null) : null,
        ];
    }
}
