<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next number of a part issue/loan form in the current tenant, per Buddhist year: "PC-2569-00001".
 * Counted in inventory_sequences with one atomic upsert.
 */
class GeneratePartCheckoutNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $prefix = 'PC-'.(now()->year + 543);

        do {
            $number = DB::selectOne(
                'insert into inventory_sequences (tenant_id, prefix, last_number, created_at, updated_at)
                 values (?, ?, 1, now(), now())
                 on conflict (tenant_id, prefix)
                 do update set last_number = inventory_sequences.last_number + 1, updated_at = now()
                 returning last_number',
                [$this->context->id(), $prefix],
            )->last_number;

            $no = sprintf('%s-%05d', $prefix, $number);
        } while (PartCheckout::withTrashed()->where('checkout_no', $no)->exists());

        return $no;
    }
}
