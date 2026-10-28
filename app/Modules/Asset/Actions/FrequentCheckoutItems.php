<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * What the company asks for most (lines of sent requests in the last DAYS days), most often
 * first: suggested on the request form before anything is typed.
 */
class FrequentCheckoutItems
{
    public const DAYS = 180;

    public const LIMIT = 10;

    /** @return list<array{item_type: string, id: int}> */
    public function handle(int $limit = self::LIMIT): array
    {
        return CheckoutItem::query()
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->whereNotIn('checkout_requests.status', [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_CANCELLED])
            ->where('checkout_items.created_at', '>=', now()->subDays(self::DAYS))
            ->groupBy('checkout_items.item_type', 'checkout_items.asset_id', 'checkout_items.part_id')
            ->select('checkout_items.item_type', 'checkout_items.asset_id', 'checkout_items.part_id', DB::raw('count(*) as times'))
            ->orderByDesc('times')
            ->orderByDesc(DB::raw('max(checkout_items.id)'))
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'item_type' => $row->item_type,
                'id' => (int) ($row->item_type === CheckoutItem::TYPE_ASSET ? $row->asset_id : $row->part_id),
            ])
            ->filter(fn (array $item) => $item['id'] > 0)
            ->values()
            ->all();
    }
}
