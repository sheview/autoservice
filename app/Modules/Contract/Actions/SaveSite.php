<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Models\CustomerSite;

/**
 * Adds a site to a customer, or changes one.
 */
class SaveSite
{
    /**
     * @param  array{name: string, address?: string|null, notes?: string|null}  $data  validated
     */
    public function handle(Customer $customer, ?CustomerSite $site, array $data): CustomerSite
    {
        $site ??= new CustomerSite(['customer_id' => $customer->id]);
        $site->fill($data)->save();

        return $site;
    }
}
