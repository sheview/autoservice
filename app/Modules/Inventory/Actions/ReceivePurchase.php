<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseAlert;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a delivery of an approved or ordered purchase request: some or all of what is still to
 * come, with what the buyer read off the goods (brand, model, serials, price paid), and puts it
 * into the system at once as what the request becomes (item_kind: an asset of its category, or
 * stock of a part; given here when the request does not say, and kept for the next deliveries).
 * The request becomes partly or fully received / registered (PurchaseWorkflow::progress) and the
 * company hears about it (alert purchase_received). Never more than was asked for, never more
 * serials than units. All or nothing: a delivery that cannot be registered is not recorded.
 */
class ReceivePurchase
{
    public function __construct(
        private LogPurchaseEvent $logEvent,
        private RegisterPurchaseReceipt $register,
    ) {}

    /**
     * @param  array{quantity: int, brand?: string|null, model?: string|null, unit_price?: int|null,
     *     serials?: list<string>, note?: string|null, item_kind?: string|null, asset_category_id?: int|null,
     *     location?: string|null, part_id?: int|null, part_code?: string|null}  $data  validated; unit_price in satang
     */
    public function handle(PurchaseRequest $request, array $data, User $actor): PurchaseReceipt
    {
        $receipt = DB::transaction(function () use ($request, $data, $actor) {
            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, PurchaseWorkflow::RECEIVABLE, true)) {
                throw ValidationException::withMessages(['quantity' => __('inventory.purchase_requests.not_allowed')]);
            }
            $left = $request->quantity - $request->qty_received;
            if ($data['quantity'] < 1 || $data['quantity'] > $left) {
                throw ValidationException::withMessages(['quantity' => __('inventory.purchase_requests.receive_over', ['left' => $left, 'unit' => $request->unit])]);
            }
            $serials = array_values(array_filter(array_map('trim', $data['serials'] ?? []), fn (string $serial) => $serial !== ''));
            if (count($serials) > $data['quantity']) {
                throw ValidationException::withMessages(['serials' => __('inventory.purchase_requests.serials_over', ['count' => $data['quantity']])]);
            }

            $receipt = $request->receipts()->create([
                'quantity' => $data['quantity'],
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'unit_price' => $data['unit_price'] ?? null,
                'serials' => $serials,
                'note' => $data['note'] ?? null,
                'received_by' => $actor->id,
                'received_by_name' => $actor->name,
                'received_at' => now(),
            ]);

            $from = $request->status;
            $request->qty_received += $data['quantity'];
            $request->status = PurchaseWorkflow::progress($request);
            // The latest delivery, as the printed request and the summaries show it.
            $request->fill(['received_by_name' => $actor->name, 'received_at' => now(), 'receive_note' => $data['note'] ?? $request->receive_note])->save();
            $this->logEvent->handle($request, 'receive', $from, $actor, __('inventory.purchase_requests.received_note', [
                'qty' => $data['quantity'], 'unit' => $request->unit, 'total' => $request->qty_received, 'of' => $request->quantity,
            ]).(filled($data['note'] ?? null) ? "\n".$data['note'] : ''));

            // Into the system as what the request becomes (said once, kept for the next deliveries).
            $kind = $data['item_kind'] ?? $request->item_kind;
            if ($kind !== null) {
                $category = $kind === PurchaseRequest::KIND_ASSET ? ($data['asset_category_id'] ?? $request->asset_category_id) : null;
                $request->update(['item_kind' => $kind, 'asset_category_id' => $category]);
                $receipt = $this->register->handle($receipt, [
                    'as' => $kind,
                    'category_id' => $category,
                    'location' => $data['location'] ?? null,
                    'part_id' => $data['part_id'] ?? null,
                    'part_code' => $data['part_code'] ?? null,
                ], $actor);
            }

            return $receipt;
        });

        $request->refresh();
        activity()->performedOn($request)->causedBy($actor)->event('purchase_receive')
            ->withProperties(['pr_no' => $request->pr_no, 'qty' => $receipt->quantity])
            ->log(__('inventory.purchase_requests.log.receive'));
        PurchaseAlert::send('purchase_received', $request, $actor->name, $receipt->note, $receipt->quantity);

        return $receipt;
    }
}
