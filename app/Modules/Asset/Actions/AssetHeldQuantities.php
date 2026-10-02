<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * How much of each asset the request lines hold now: asked for (a submitted request waiting for
 * approval), approved and not handed out yet, or handed out and not back. What is left of the
 * asset's quantity may still be asked for.
 */
class AssetHeldQuantities
{
    /** The SQL of what one line holds (columns of checkout_items). */
    public const HELD_SQL = "case
        when checkout_items.status = 'pending' then checkout_items.qty_requested
        when checkout_items.status in ('approved', 'partial', 'backordered') then coalesce(checkout_items.qty_approved, 0) - checkout_items.qty_returned
        when checkout_items.status = 'fulfilled' then checkout_items.qty_fulfilled - checkout_items.qty_returned
        else 0 end";

    /**
     * @param  list<int>  $assetIds
     * @return array<int, int> asset id => quantity held (assets holding nothing are left out)
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
            ->groupBy('checkout_items.asset_id')
            ->select('checkout_items.asset_id', DB::raw('sum('.self::HELD_SQL.') as held'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->asset_id => max(0, (int) $row->held)])
            ->filter()
            ->all();
    }
}
