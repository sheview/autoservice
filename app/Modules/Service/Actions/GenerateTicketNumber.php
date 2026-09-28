<?php

namespace App\Modules\Service\Actions;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Next ticket number of the current tenant: TK-{Buddhist year}-00001, restarting every year.
 * The counter row is bumped with one atomic upsert, so parallel requests never share a number.
 */
class GenerateTicketNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $year = now()->year + 543;

        $number = DB::selectOne(
            'insert into ticket_number_sequences (tenant_id, year, last_number, created_at, updated_at)
             values (?, ?, 1, now(), now())
             on conflict (tenant_id, year)
             do update set last_number = ticket_number_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [$this->context->id(), $year],
        )->last_number;

        return sprintf('TK-%d-%05d', $year, $number);
    }
}
