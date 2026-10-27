<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next free customer code of the current tenant: "CUST001", "CUST002", ... after the highest one
 * in use (hand-typed codes of another shape are left alone; past 999 it simply grows a digit).
 * Call inside a transaction: a lock on the tenant's row (Counter::lockTenant) keeps two customers
 * made at the same time apart.
 */
class GenerateCustomerCode
{
    public const PREFIX = 'CUST';

    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        Counter::lockTenant($this->context->id());

        $last = Customer::withTrashed()
            ->whereRaw("code REGEXP '^CUST[0-9]+$'")
            ->selectRaw('max(cast(substring(code, 5) as unsigned)) as last')
            ->value('last');

        return self::PREFIX.sprintf('%03d', ((int) $last) + 1);
    }
}
