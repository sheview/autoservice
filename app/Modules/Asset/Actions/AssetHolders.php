<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;

/**
 * Who has each asset now: the borrowers of request lines handed out and not back yet (lent or
 * issued), oldest first. Lines only asked for or approved are not in anyone's hands yet.
 * Keyed by asset id; assets nobody holds are left out.
 */
class AssetHolders
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, list<array{name: string, type: string, qty: int, due_return_date: string|null, request_ulid: string}>>
     */
    public function handle(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return CheckoutItem::query()
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->whereNotIn('checkout_requests.status', [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_CANCELLED, CheckoutRequest::STATUS_REJECTED])
            ->where('checkout_items.item_type', CheckoutItem::TYPE_ASSET)
            ->whereIn('checkout_items.asset_id', $assetIds)
            ->whereColumn('checkout_items.qty_fulfilled', '>', 'checkout_items.qty_returned')
            ->orderBy('checkout_items.id')
            ->get([
                'checkout_items.asset_id', 'checkout_items.checkout_type', 'checkout_items.qty_fulfilled', 'checkout_items.qty_returned',
                'checkout_items.due_return_date', 'checkout_requests.borrower_name', 'checkout_requests.ulid as request_ulid',
            ])
            ->groupBy('asset_id')
            ->map(fn ($lines) => $lines->map(fn (CheckoutItem $line) => [
                'name' => $line->borrower_name,
                'type' => $line->checkout_type,
                'qty' => $line->qty_fulfilled - $line->qty_returned,
                'due_return_date' => $line->due_return_date?->toDateString(),
                'request_ulid' => $line->request_ulid,
            ])->values()->all())
            ->all();
    }
}
