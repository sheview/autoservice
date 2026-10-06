<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * The requests asked for on the same form as this one (its batch), in the order they were
 * asked; a request asked for alone is its own batch.
 */
class PurchaseBatchOf
{
    /**
     * @return Collection<int, PurchaseRequest>
     */
    public function handle(PurchaseRequest $request): Collection
    {
        return $request->batch === null
            ? new Collection([$request])
            : PurchaseRequest::query()->where('batch', $request->batch)->orderBy('id')->get();
    }
}
