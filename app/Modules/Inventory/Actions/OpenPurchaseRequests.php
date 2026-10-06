<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Several items asked for on one form: one purchase request per item (SavePurchaseRequest), all
 * with the same reason, date, project and quotations, sharing a batch so they are shown and
 * decided on together. A single item is a request of its own, without a batch.
 */
class OpenPurchaseRequests
{
    public function __construct(private SavePurchaseRequest $save) {}

    /**
     * @param  array<string, mixed>  $shared  what all items share (reason, needed_by, contract_id, checkout_request_id)
     * @param  list<array<string, mixed>>  $items  each item's own fields (item_name, quantity, unit, unit_price in satang, links, ...)
     * @param  list<UploadedFile>  $files  quotations, attached to every request
     * @return list<PurchaseRequest>
     */
    public function handle(array $shared, array $items, User $actor, array $files = []): array
    {
        $batch = count($items) > 1 ? (string) Str::ulid() : null;

        return DB::transaction(fn () => array_map(
            fn (array $item) => $this->save->handle(null, [...$shared, ...$item, 'batch' => $batch], $actor, $files),
            $items,
        ));
    }
}
