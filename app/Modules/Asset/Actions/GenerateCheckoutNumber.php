<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next number of an issue/loan form in the current tenant, per Buddhist year: "AC-2569-00001".
 * Counted in asset_code_sequences (category prefixes are letters and digits only, so "AC-2569"
 * never meets one), bumped with one atomic upsert like the asset codes.
 */
class GenerateCheckoutNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $prefix = 'AC-'.(now()->year + 543);

        do {
            $number = DB::selectOne(
                'insert into asset_code_sequences (tenant_id, prefix, last_number, created_at, updated_at)
                 values (?, ?, 1, now(), now())
                 on conflict (tenant_id, prefix)
                 do update set last_number = asset_code_sequences.last_number + 1, updated_at = now()
                 returning last_number',
                [$this->context->id(), $prefix],
            )->last_number;

            $no = sprintf('%s-%05d', $prefix, $number);
        } while (AssetCheckout::withTrashed()->where('checkout_no', $no)->exists());

        return $no;
    }
}
