<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next free asset code of a prefix in the current tenant: "PC-00001", "PC-00002", ...
 *
 * The counter row is bumped with one atomic upsert, so two users saving at the same time
 * never get the same number. Numbers already taken by a hand-typed code are skipped.
 */
class GenerateAssetCode
{
    public function __construct(private TenantContext $context) {}

    public function handle(string $prefix): string
    {
        do {
            $number = DB::selectOne(
                'insert into asset_code_sequences (tenant_id, prefix, last_number, created_at, updated_at)
                 values (?, ?, 1, now(), now())
                 on conflict (tenant_id, prefix)
                 do update set last_number = asset_code_sequences.last_number + 1, updated_at = now()
                 returning last_number',
                [$this->context->id(), $prefix],
            )->last_number;

            $code = sprintf('%s-%05d', $prefix, $number);
        } while (Asset::withTrashed()->where('asset_code', $code)->exists());

        return $code;
    }
}
