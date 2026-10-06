<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Next PM round number of the current tenant: PM-{Buddhist year}-00001, restarting every year.
 * The counter row is bumped with one upsert (Counter), so parallel requests never share a number.
 */
class GeneratePmNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $year = now()->year + 543;

        $number = Counter::next('pm_number_sequences', 'year', $year, $this->context->id());

        return sprintf('PM-%d-%05d', $year, $number);
    }
}
