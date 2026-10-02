<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Counts what was handed out of a purchase (an issue/loan line tied to it, Asset module). When all
 * of it is out the request is "issued"; until then it shows how much has gone.
 */
class RecordPurchaseIssued
{
    public function __construct(private LogPurchaseEvent $logEvent) {}

    public function handle(int $purchaseRequestId, int $qty, ?User $actor): void
    {
        DB::transaction(function () use ($purchaseRequestId, $qty, $actor) {
            $request = PurchaseRequest::query()->lockForUpdate()->find($purchaseRequestId);
            if ($request === null || $qty < 1) {
                return;
            }
            $from = $request->status;
            $request->qty_issued = min($request->quantity, $request->qty_issued + $qty);
            $request->status = PurchaseWorkflow::progress($request);
            $request->save();
            $this->logEvent->handle($request, 'issue', $from, $actor, __('inventory.purchase_requests.issued_note', [
                'qty' => $qty, 'unit' => $request->unit, 'total' => $request->qty_issued, 'of' => $request->quantity,
            ]));
        });
    }
}
