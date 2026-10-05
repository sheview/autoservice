<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;

/**
 * Issue/loan request lines by id, as other modules point to them (the pieces of a part that went
 * out on a line): the request's number and link, and who received the things.
 */
class CheckoutLineLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, request_no: string, borrower_name: string}> keyed by line id
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : CheckoutItem::query()->with('request:id,ulid,request_no,borrower_name')->whereKey($ids)->get()
            ->filter(fn (CheckoutItem $item) => $item->request !== null)
            ->mapWithKeys(fn (CheckoutItem $item) => [$item->id => $item->request->only(['ulid', 'request_no', 'borrower_name'])])
            ->all();
    }
}
