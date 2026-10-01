<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Money;

/**
 * A received purchase request as plain data, for registering what arrived as assets (Asset module).
 * Null when it is not received or the user may not see it.
 */
class PurchaseRequestDetails
{
    /**
     * @return array{pr_no: string, item_name: string, description: string|null, quantity: int, unit_price: string|null,
     *     received_on: string|null, requested_by_name: string|null}|null
     */
    public function handle(string $ulid, User $user): ?array
    {
        $request = PurchaseRequest::query()->where('ulid', $ulid)->first();
        if ($request === null || $request->status !== PurchaseRequest::STATUS_RECEIVED || ! $user->can('view', $request)) {
            return null;
        }

        return [
            ...$request->only(['pr_no', 'item_name', 'description', 'quantity', 'requested_by_name']),
            'unit_price' => Money::toBaht($request->unit_price),
            'received_on' => $request->received_at?->toDateString(),
        ];
    }
}
