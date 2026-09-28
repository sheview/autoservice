<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;

/**
 * Creates or updates a customer of the current tenant.
 */
class SaveCustomer
{
    /**
     * @param  array{code: string, name: string, tax_id?: string|null, contact_name?: string|null,
     *     phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function handle(?Customer $customer, array $data): Customer
    {
        $customer ??= new Customer;
        $data['code'] = strtoupper(trim($data['code']));
        $customer->fill($data)->save();

        return $customer;
    }
}
