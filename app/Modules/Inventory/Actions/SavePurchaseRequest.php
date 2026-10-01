<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Opens a purchase request (numbered, waiting for approval) or changes one that still waits.
 * Quotations come as attached files.
 */
class SavePurchaseRequest
{
    public function __construct(
        private GeneratePurchaseRequestNumber $generateNumber,
        private AddAttachments $addAttachments,
    ) {}

    /**
     * @param  array{item_name: string, contract_id?: int|null, description?: string|null, quantity: int, unit: string, unit_price?: int|null,
     *     links?: list<string>, reason?: string|null, needed_by?: string|null}  $data  validated; unit_price in satang
     * @param  list<UploadedFile>  $files
     */
    public function handle(?PurchaseRequest $request, array $data, User $actor, array $files = []): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $data, $actor, $files) {
            $fields = [
                ...collect($data)->only(['contract_id', 'item_name', 'description', 'quantity', 'unit', 'unit_price', 'reason', 'needed_by'])->all(),
                'links' => array_values(array_filter(array_map('trim', $data['links'] ?? []))),
            ];

            if ($request === null) {
                $request = PurchaseRequest::create([
                    ...$fields,
                    'pr_no' => $this->generateNumber->handle(),
                    'status' => PurchaseRequest::STATUS_PENDING,
                    'requested_by' => $actor->id,
                    'requested_by_name' => $actor->name,
                ]);
            } else {
                $request->update($fields);
            }

            $this->addAttachments->handle($request, $files);

            return $request;
        });
    }
}
