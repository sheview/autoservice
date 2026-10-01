<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Money;

/**
 * A purchase request as the pages show it.
 */
class PurchaseRequestRow
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PurchaseRequest $request): array
    {
        return [
            ...$request->only([
                'ulid', 'pr_no', 'status', 'item_name', 'description', 'quantity', 'unit', 'links', 'reason', 'requested_by', 'requested_by_name',
                'decided_by_name', 'decision_note', 'ordered_by_name', 'order_note', 'received_by_name', 'receive_note',
            ]),
            'unit_price' => Money::toBaht($request->unit_price),
            'total' => $request->unit_price === null ? null : Money::toBaht($request->unit_price * $request->quantity),
            'needed_by' => $request->needed_by?->toDateString(),
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'ordered_at' => $request->ordered_at?->toIso8601String(),
            'received_at' => $request->received_at?->toIso8601String(),
        ];
    }
}
