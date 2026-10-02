<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PurchaseRequest;

/**
 * Number and status of purchase requests by id, for other modules showing a link to them (a
 * backordered issue/loan line that waits for a purchase).
 */
class PurchaseRequestLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, pr_no: string, status: string}> keyed by id
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : PurchaseRequest::query()->whereKey($ids)->get(['id', 'ulid', 'pr_no', 'status'])
            ->mapWithKeys(fn (PurchaseRequest $pr) => [$pr->id => $pr->only(['ulid', 'pr_no', 'status'])])
            ->all();
    }
}
