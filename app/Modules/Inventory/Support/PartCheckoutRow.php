<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;

/**
 * A part issue/loan form as the pages get it. Same shape as the Asset module's CheckoutRow, so
 * the issue/loan components serve both: the part is under "asset" (code as asset_code, part
 * number as serial_number), and "kind" says which it is.
 */
class PartCheckoutRow
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PartCheckout $checkout): array
    {
        $part = $checkout->relationLoaded('part') ? $checkout->part : null;

        return [
            'kind' => 'part',
            ...$checkout->only([
                'ulid', 'checkout_no', 'contract_id', 'type', 'quantity', 'status', 'borrower_user_id', 'borrower_name', 'borrower_department', 'borrower_phone', 'purpose',
                'requested_by', 'requested_by_name', 'decided_by_name', 'decision_note', 'returned_by_name', 'return_note',
            ]),
            'due_on' => $checkout->due_on?->toDateString(),
            'overdue' => $checkout->isOverdue(),
            'requested_at' => $checkout->created_at?->toIso8601String(),
            'decided_at' => $checkout->decided_at?->toIso8601String(),
            'returned_at' => $checkout->returned_at?->toIso8601String(),
            'asset' => $part instanceof Part ? [
                'ulid' => (string) $part->id,
                'asset_code' => $part->code,
                'name' => $part->name,
                'serial_number' => $part->part_number,
                'unit' => $part->unit,
            ] : null,
        ];
    }
}
