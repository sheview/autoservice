<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Support\RequestAlert;
use Illuminate\Support\Facades\DB;

/**
 * Parts came into stock: the backordered lines of that part are ready to hand out again, and the
 * requester and those who hand out hear about it (alert checkout_restocked).
 */
class RestockBackorders
{
    /** @return int how many lines are ready again */
    public function handle(int $partId): int
    {
        $items = CheckoutItem::query()
            ->with('request')
            ->where('item_type', CheckoutItem::TYPE_PART)
            ->where('part_id', $partId)
            ->where('status', CheckoutItem::STATUS_BACKORDERED)
            ->get();

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $item->update([
                    'status' => $item->qty_fulfilled > 0 ? CheckoutItem::STATUS_PARTIAL : CheckoutItem::STATUS_APPROVED,
                    'backorder_alerted_at' => null,
                ]);
                RequestAlert::send('checkout_restocked', $item->request, null, null, $item);
            }
        });

        return $items->count();
    }
}
