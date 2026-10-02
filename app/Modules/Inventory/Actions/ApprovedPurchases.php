<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PurchaseRequest;

/**
 * Which of these purchase requests were approved and brought something into the system, with
 * how much of it is still to hand out. An issue/loan line handing that out needs no second
 * approval (Asset module, SubmitCheckoutRequest): it was approved when it was asked to buy.
 */
class ApprovedPurchases
{
    /**
     * @param  list<int>  $ids
     * @return array<int, int> id => quantity registered and not handed out yet
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : PurchaseRequest::query()->whereKey($ids)
            ->whereIn('status', PurchaseRequest::RECEIVING_STATUSES)
            ->get(['id', 'qty_registered', 'qty_issued'])
            ->mapWithKeys(fn (PurchaseRequest $pr) => [$pr->id => max(0, $pr->qty_registered - $pr->qty_issued)])
            ->all();
    }
}
