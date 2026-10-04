<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use Carbon\CarbonInterface;

/**
 * What a person borrowed and still has to give back, by due date, for their "my work" calendar
 * (Service module): every unreturned loan due up to $to (earlier ones are overdue).
 */
class LoansDueFor
{
    /**
     * @return list<array{id: int, request_ulid: string, request_no: string, item_name: string, qty: int, unit: string|null, due: string}>
     */
    public function handle(int $userId, CarbonInterface $to): array
    {
        return CheckoutItem::query()
            ->with('request:id,ulid,request_no,borrower_user_id')
            ->where('checkout_type', CheckoutItem::LOAN)
            ->whereNotNull('due_return_date')
            ->where('due_return_date', '<=', $to->toDateString())
            ->whereColumn('qty_returned', '<', 'qty_fulfilled')
            ->whereHas('request', fn ($q) => $q->where('borrower_user_id', $userId))
            ->orderBy('due_return_date')
            ->get()
            ->map(fn (CheckoutItem $item) => [
                'id' => $item->id,
                'request_ulid' => $item->request->ulid,
                'request_no' => $item->request->request_no,
                'item_name' => $item->item_name,
                'qty' => (int) $item->qty_fulfilled - (int) $item->qty_returned,
                'unit' => $item->unit,
                'due' => $item->due_return_date->toDateString(),
            ])
            ->all();
    }
}
