<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next purchase request number of the current tenant, per Buddhist year: "PR-2569-00001".
 * Call inside a transaction: locking the tenant's row (Counter::lockTenant) keeps two
 * requests saved at the same time from getting the same number.
 */
class GeneratePurchaseRequestNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $prefix = 'PR-'.(now()->year + 543).'-';

        Counter::lockTenant($this->context->id());

        $last = PurchaseRequest::withTrashed()->where('pr_no', 'like', $prefix.'%')->max('pr_no');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.sprintf('%05d', $next);
    }
}
