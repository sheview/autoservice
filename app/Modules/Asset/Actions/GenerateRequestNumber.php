<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next number of an issue/loan request in the current tenant, per Buddhist year: "CR-2569-00001".
 * Counted in asset_code_sequences ("CR-2569" never meets a category prefix) with one atomic upsert.
 */
class GenerateRequestNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $prefix = 'CR-'.(now()->year + 543);

        do {
            $number = Counter::next('asset_code_sequences', 'prefix', $prefix, $this->context->id());

            $no = sprintf('%s-%05d', $prefix, $number);
        } while (CheckoutRequest::withTrashed()->where('request_no', $no)->exists());

        return $no;
    }
}
