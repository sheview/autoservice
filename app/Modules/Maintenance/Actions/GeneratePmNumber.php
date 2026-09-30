<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next PM round number of the current tenant: PM-{Buddhist year}-00001, restarting every year.
 * The counter row is bumped with one atomic upsert, so parallel requests never share a number.
 */
class GeneratePmNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $year = now()->year + 543;

        $number = DB::selectOne(
            'insert into pm_number_sequences (tenant_id, year, last_number, created_at, updated_at)
             values (?, ?, 1, now(), now())
             on conflict (tenant_id, year)
             do update set last_number = pm_number_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [$this->context->id(), $year],
        )->last_number;

        return sprintf('PM-%d-%05d', $year, $number);
    }
}
