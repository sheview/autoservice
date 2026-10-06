<?php

namespace App\Modules\Service\Actions;

use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next ticket number of the current tenant: TK-{Buddhist year}-00001, restarting every year.
 * The counter row is bumped with one upsert (Counter), so parallel requests never share a number.
 */
class GenerateTicketNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $year = now()->year + 543;

        $number = Counter::next('ticket_number_sequences', 'year', $year, $this->context->id());

        return sprintf('TK-%d-%05d', $year, $number);
    }
}
