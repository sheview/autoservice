<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next free asset code of a prefix in the current tenant: "PC-00001", "PC-00002", ...
 *
 * The counter row is bumped with one upsert (Counter), so two users saving at the same time
 * never get the same number. Numbers already taken by a hand-typed code are skipped.
 */
class GenerateAssetCode
{
    public function __construct(private TenantContext $context) {}

    public function handle(string $prefix): string
    {
        do {
            $number = Counter::next('asset_code_sequences', 'prefix', $prefix, $this->context->id());

            $code = sprintf('%s-%05d', $prefix, $number);
        } while (Asset::withTrashed()->where('asset_code', $code)->exists());

        return $code;
    }
}
