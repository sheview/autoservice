<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseReceiptAsset;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestEvent;
use App\Modules\Platform\Support\Money;

/**
 * A purchase request as the pages show it, with its deliveries and history for its own page.
 */
class PurchaseRequestRow
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PurchaseRequest $request): array
    {
        return [
            ...$request->only([
                'ulid', 'pr_no', 'contract_id', 'checkout_request_id', 'status', 'item_name', 'description',
                'quantity', 'qty_received', 'qty_registered', 'qty_issued', 'unit', 'item_kind', 'asset_category_id', 'links', 'reason', 'requested_by', 'requested_by_name',
                'decided_by_name', 'decision_note', 'ordered_by_name', 'order_note', 'received_by_name', 'receive_note',
            ]),
            'unit_price' => Money::toBaht($request->unit_price),
            'total' => $request->unit_price === null ? null : Money::toBaht($request->unit_price * $request->quantity),
            'needed_by' => $request->needed_by?->toDateString(),
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'ordered_at' => $request->ordered_at?->toIso8601String(),
            'received_at' => $request->received_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array{ulid: string, asset_code: string, name: string}>  $assets  the assets receipts became, by id
     * @return array<string, mixed>
     */
    public static function receipt(PurchaseReceipt $receipt, array $assets = []): array
    {
        return [
            ...$receipt->only(['id', 'quantity', 'brand', 'model', 'serials', 'note', 'received_by_name', 'registered_as', 'registered_by_name']),
            'unit_price' => Money::toBaht($receipt->unit_price),
            'received_at' => $receipt->received_at?->toIso8601String(),
            'registered_at' => $receipt->registered_at?->toIso8601String(),
            'assets' => $receipt->assets->map(fn (PurchaseReceiptAsset $row) => $assets[$row->asset_id] ?? null)->filter()->values()->all(),
            'part' => $receipt->part_id ? $receipt->part?->only(['id', 'code', 'name']) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function event(PurchaseRequestEvent $event): array
    {
        return [
            ...$event->only(['id', 'action', 'from_status', 'to_status', 'actor_name', 'note']),
            'at' => $event->created_at?->toIso8601String(),
        ];
    }
}
