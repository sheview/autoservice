<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;

/**
 * Where requests of the current company stand, as plain arrays, for the company that asked
 * through a share (Platform\CrossTenant): number, status, and each line's quantities.
 */
class CheckoutRequestStatuses
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{request_no: string, status: string, reject_reason: string|null, approved_by_name: string|null,
     *     items: list<array{item_name: string, item_type: string, checkout_type: string, unit: string|null,
     *     qty_requested: int, qty_approved: int|null, qty_fulfilled: int, status: string}>}>
     */
    public function handle(array $ids): array
    {
        return CheckoutRequest::query()
            ->with('items')
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (CheckoutRequest $request) => [$request->id => [
                ...$request->only(['request_no', 'status', 'reject_reason', 'approved_by_name']),
                'items' => $request->items->map(fn (CheckoutItem $item) => [
                    ...$item->only(['item_name', 'item_type', 'checkout_type', 'unit', 'qty_approved', 'status']),
                    'qty_requested' => (int) $item->qty_requested,
                    'qty_fulfilled' => (int) $item->qty_fulfilled,
                ])->values()->all(),
            ]])
            ->all();
    }
}
