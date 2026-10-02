<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next free part code of the current tenant: "PT-00001", "PT-00002", ... after the highest one
 * in use (hand-typed codes of another shape are left alone). Call inside a transaction: a
 * transaction-level advisory lock per tenant keeps two parts made at the same time apart.
 */
class GeneratePartCode
{
    public const PREFIX = 'PT-';

    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        DB::select('select pg_advisory_xact_lock(?)', [crc32('part_codes:'.$this->context->id())]);

        $last = Part::withTrashed()
            ->whereRaw("code ~ '^PT-[0-9]+$'")
            ->selectRaw('max(substring(code from 4)::bigint) as last')
            ->value('last');

        return self::PREFIX.sprintf('%05d', ((int) $last) + 1);
    }
}
