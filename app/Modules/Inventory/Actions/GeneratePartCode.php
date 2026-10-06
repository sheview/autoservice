<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next free part code of the current tenant: "PT-00001", "PT-00002", ... after the highest one
 * in use (hand-typed codes of another shape are left alone). Call inside a transaction: a
 * lock on the tenant's row (Counter::lockTenant) keeps two parts made at the same time apart.
 */
class GeneratePartCode
{
    public const PREFIX = 'PT-';

    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        Counter::lockTenant($this->context->id());

        $last = Part::withTrashed()
            ->whereRaw("code REGEXP '^PT-[0-9]+$'")
            ->selectRaw('max(cast(substring(code, 4) as unsigned)) as last')
            ->value('last');

        return self::PREFIX.sprintf('%05d', ((int) $last) + 1);
    }
}
