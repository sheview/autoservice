<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An issue/loan request of the current company made by a person of another company, through a
 * share (Platform\CrossTenant). It goes straight to the approver: never approved on its own,
 * whatever the auto-approve limit. The person has no account here, so they are named, not linked.
 */
class OpenOutsideCheckoutRequest
{
    public function __construct(private SaveCheckoutRequest $saveRequest) {}

    /**
     * @param  string  $requester  e.g. "Somchai (Company A)"
     * @param  array{purpose?: string|null, needed_by?: string|null, borrower_phone?: string|null,
     *     items: list<array{item_type: string, asset_id?: int|null, part_id?: int|null, checkout_type?: string|null,
     *     qty?: int|null, due_return_date?: string|null, note?: string|null}>}  $data  validated
     */
    /**
     * @param  list<int>  $branchIds  the share's branches: assets only of these (empty = any)
     */
    public function handle(string $requester, array $data, array $branchIds = []): CheckoutRequest
    {
        $assetIds = collect($data['items'])->where('item_type', CheckoutItem::TYPE_ASSET)->pluck('asset_id')->map(fn ($id) => (int) $id);
        if ($branchIds !== [] && Asset::query()->whereKey($assetIds)->whereNotIn('branch_id', $branchIds)->orWhere(fn ($q) => $q->whereKey($assetIds)->whereNull('branch_id'))->exists()) {
            throw ValidationException::withMessages(['items' => __('asset.requests.asset_not_available')]);
        }

        return DB::transaction(function () use ($requester, $data) {
            $request = $this->saveRequest->handle(null, [
                ...$data,
                'borrower_user_id' => null,
                'borrower_name' => $requester,
                'ticket_id' => null,
                'contract_id' => null,
            ], null, $requester);

            $request->update(['status' => CheckoutRequest::STATUS_PENDING, 'submitted_at' => now()]);
            RequestAlert::send('checkout_requested', $request, $requester);

            return $request;
        });
    }
}
