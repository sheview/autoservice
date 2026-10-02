<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Asset\Actions\RegisterPurchasedAsset;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a delivery into the system, filled in from the purchase request and the receipt (name,
 * unit, brand, model, serials, price, date):
 *
 *   asset  spare assets in the chosen category (Asset module, RegisterPurchasedAsset): one per serial
 *          in a category counted by serial, else one holding the quantity
 *   part   received into stock of an existing part, or of a new part with the code given
 *          (or the next "PT-00001" code when none is given)
 *
 * Once everything asked for is registered the request is "registered" and can be handed out.
 * All or nothing.
 */
class RegisterPurchaseReceipt
{
    public function __construct(
        private RegisterPurchasedAsset $registerAsset,
        private SavePart $savePart,
        private GeneratePartCode $generatePartCode,
        private RecordStockMovement $recordMovement,
        private LogPurchaseEvent $logEvent,
    ) {}

    /**
     * @param  array{as: string, category_id?: int|null, location?: string|null, part_id?: int|null, part_code?: string|null}  $data  validated
     */
    public function handle(PurchaseReceipt $receipt, array $data, User $actor): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $actor) {
            $receipt = PurchaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->registered_at !== null) {
                throw ValidationException::withMessages(['as' => __('inventory.purchase_requests.already_registered')]);
            }
            $request = $receipt->purchaseRequest;
            $price = $receipt->unit_price ?? $request->unit_price;
            $note = __('inventory.purchase_requests.registered_from', ['no' => $request->pr_no, 'name' => $request->requested_by_name ?? '-']);

            $assets = [];
            if ($data['as'] === PurchaseReceipt::AS_ASSET) {
                $assets = $this->registerAsset->handle([
                    'category_id' => (int) $data['category_id'],
                    'name' => $request->item_name,
                    'brand' => $receipt->brand,
                    'model' => $receipt->model,
                    'quantity' => $receipt->quantity,
                    'unit' => $request->unit,
                    'location' => $data['location'] ?? null,
                    'purchased_at' => $receipt->received_at->toDateString(),
                    'purchase_price' => $price,
                    'notes' => $note,
                ], $receipt->serials, $actor);
                $receipt->fill(['registered_as' => PurchaseReceipt::AS_ASSET]);
            } else {
                // The part chosen; or, with no code typed, an active part of the same name; or a new one.
                $part = match (true) {
                    filled($data['part_id'] ?? null) => Part::query()->findOrFail($data['part_id']),
                    blank($data['part_code'] ?? null) => Part::query()->where('is_active', true)
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim($request->item_name))])->orderBy('id')->first(),
                    default => null,
                } ?? $this->savePart->handle(null, [
                    'code' => filled($data['part_code'] ?? null) ? (string) $data['part_code'] : $this->generatePartCode->handle(),
                    'name' => $request->item_name,
                    'unit' => $request->unit,
                    'brand' => $receipt->brand,
                    'part_number' => $receipt->model,
                    'contract_id' => $request->contract_id,
                    'unit_cost' => $price,
                    'notes' => $note,
                ]);
                $movement = $this->recordMovement->handle($part, StockMovement::TYPE_RECEIVE, $receipt->quantity, $actor, [
                    'unit_cost' => $price,
                    'reference' => $request->pr_no,
                    'note' => $note,
                ]);
                $receipt->fill(['registered_as' => PurchaseReceipt::AS_PART, 'part_id' => $part->id, 'stock_movement_id' => $movement->id]);
            }

            $receipt->fill(['registered_by_name' => $actor->name, 'registered_at' => now()])->save();
            foreach ($assets as $asset) {
                $receipt->assets()->create(['asset_id' => $asset['id'], 'quantity' => $asset['quantity']]);
            }

            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($request->id);
            $from = $request->status;
            $request->qty_registered += $receipt->quantity;
            $request->status = PurchaseWorkflow::progress($request);
            $request->save();
            $this->logEvent->handle($request, 'register', $from, $actor, __("inventory.purchase_requests.registered_as.{$receipt->registered_as}", [
                'qty' => $receipt->quantity, 'unit' => $request->unit,
            ]));

            activity()->performedOn($request)->causedBy($actor)->event('purchase_register')
                ->withProperties(['pr_no' => $request->pr_no, 'as' => $receipt->registered_as, 'qty' => $receipt->quantity])
                ->log(__('inventory.purchase_requests.log.register'));

            return $receipt;
        });
    }
}
