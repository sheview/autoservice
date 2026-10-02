<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Asset\Actions\FreeAssetUnits;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseReceiptAsset;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Modules;

/**
 * What a purchase request brought into the system and is not handed out yet, for starting an
 * issue/loan request with it (Asset module): the assets and parts its receipts were registered
 * as, each with the quantity still to hand out. Null when there is nothing (or the user may not
 * see the request).
 */
class PurchaseIssueLines
{
    /**
     * @return array{id: int, ulid: string, pr_no: string, requested_by: int|null, contract_id: int|null, checkout_request_id: int|null,
     *     lines: list<array{item_type: string, id: int, qty: int}>}|null
     */
    public function handle(string $ulid, User $user): ?array
    {
        $request = PurchaseRequest::query()->where('ulid', $ulid)->first();
        if ($request === null || ! $user->can('view', $request) || $request->qty_registered <= $request->qty_issued) {
            return null;
        }

        $units = $request->receipts()->with('assets')->whereNotNull('registered_at')->get()
            ->flatMap(fn (PurchaseReceipt $receipt) => $receipt->registered_as === PurchaseReceipt::AS_ASSET
                ? $receipt->assets->map(fn (PurchaseReceiptAsset $row) => ['item_type' => 'asset', 'id' => (int) $row->asset_id, 'qty' => $row->quantity])
                : [['item_type' => 'part', 'id' => (int) $receipt->part_id, 'qty' => $receipt->quantity]]);

        // Assets: each device as far as it is still free (whichever went out first). Parts are
        // all alike: what was handed out is taken from the earliest deliveries.
        $free = app(Modules::class)->enabled('asset')
            ? app(FreeAssetUnits::class)->handle($units->where('item_type', 'asset')->pluck('id')->all())
            : [];
        $left = $request->qty_registered - $request->qty_issued;
        $issued = $request->qty_issued;
        $lines = [];
        foreach ($units as $unit) {
            if ($unit['item_type'] === 'asset') {
                $qty = min($unit['qty'], $free[$unit['id']] ?? 0);
            } else {
                $qty = $unit['qty'] - min($issued, $unit['qty']);
                $issued = max(0, $issued - $unit['qty']);
            }
            $qty = min($qty, $left);
            if ($qty > 0) {
                $lines[] = [...$unit, 'qty' => $qty];
                $left -= $qty;
            }
        }

        return [
            ...$request->only(['id', 'ulid', 'pr_no', 'requested_by', 'contract_id', 'checkout_request_id']),
            'lines' => $lines,
        ];
    }
}
