<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Opens a purchase request (numbered, waiting for approval) or changes one that still waits.
 * Quotations come as attached files. A new request is written into its history and announced
 * (alert purchase_requested); checkout_request_id is the issue/loan request it was asked from.
 */
class SavePurchaseRequest
{
    public function __construct(
        private GeneratePurchaseRequestNumber $generateNumber,
        private AddAttachments $addAttachments,
        private LogPurchaseEvent $logEvent,
    ) {}

    /**
     * @param  array{item_name: string, contract_id?: int|null, description?: string|null, quantity: int, unit: string, unit_price?: int|null,
     *     links?: list<string>, reason?: string|null, needed_by?: string|null, checkout_request_id?: int|null,
     *     item_kind?: string|null, asset_category_id?: int|null, batch?: string|null}  $data  validated; unit_price in satang
     * @param  list<UploadedFile>  $files
     */
    public function handle(?PurchaseRequest $request, array $data, User $actor, array $files = []): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $data, $actor, $files) {
            $fields = [
                ...collect($data)->only(['contract_id', 'item_name', 'description', 'quantity', 'unit', 'unit_price', 'reason', 'needed_by'])->all(),
                'item_kind' => $data['item_kind'] ?? null,
                // A category only for an asset.
                'asset_category_id' => ($data['item_kind'] ?? null) === PurchaseRequest::KIND_ASSET ? ($data['asset_category_id'] ?? null) : null,
                'links' => array_values(array_filter(array_map('trim', $data['links'] ?? []))),
            ];

            if ($request === null) {
                $request = PurchaseRequest::create([
                    ...$fields,
                    'pr_no' => $this->generateNumber->handle(),
                    'status' => PurchaseRequest::STATUS_PENDING,
                    'requested_by' => $actor->id,
                    'requested_by_name' => $actor->name,
                    'checkout_request_id' => $data['checkout_request_id'] ?? null,
                    // Asked for together with other items on one form (OpenPurchaseRequests).
                    'batch' => $data['batch'] ?? null,
                ]);
                $this->logEvent->handle($request, 'create', null, $actor);
                PurchaseAlert::send('purchase_requested', $request, $actor->name);
            } else {
                $request->update($fields);
            }

            $this->addAttachments->handle($request, $files);

            return $request;
        });
    }
}
