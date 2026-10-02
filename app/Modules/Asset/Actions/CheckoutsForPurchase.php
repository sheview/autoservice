<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;

/**
 * The issue/loan requests tied to a purchase request (Inventory module), for its page: the one
 * it was asked from, and those with lines handing out what it brought. Each with whether its
 * papers (issue/loan form, delivery note) can be printed. Only those the user may see.
 */
class CheckoutsForPurchase
{
    /**
     * @return list<array{ulid: string, request_no: string, status: string, borrower_name: string, source: bool, printable: bool, delivered: bool, editable: bool}>
     */
    public function handle(int $purchaseRequestId, ?int $sourceId, User $user): array
    {
        $ids = CheckoutItem::query()->where('purchase_request_id', $purchaseRequestId)->pluck('request_id')
            ->push($sourceId)->filter()->unique()->all();

        return CheckoutRequest::query()->whereKey($ids)->withSum('items', 'qty_fulfilled')->orderBy('id')->get()
            ->filter(fn (CheckoutRequest $checkout) => $user->can('view', $checkout))
            ->map(fn (CheckoutRequest $checkout) => [
                ...$checkout->only(['ulid', 'request_no', 'status', 'borrower_name']),
                'source' => $checkout->id === $sourceId,
                'printable' => ! in_array($checkout->status, CheckoutRequest::UNPRINTABLE, true),
                'delivered' => (int) $checkout->items_sum_qty_fulfilled > 0,
                // A draft the user may still change: what was bought is added to it.
                'editable' => $user->can('update', $checkout),
            ])->values()->all();
    }
}
