<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a customer of the current tenant. A new customer with no code gets the next
 * free one ("CUST001", GenerateCustomerCode).
 */
class SaveCustomer
{
    public function __construct(private GenerateCustomerCode $generateCode) {}

    /**
     * @param  array{code?: string|null, name: string, tax_id?: string|null, contact_name?: string|null,
     *     phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function handle(?Customer $customer, array $data): Customer
    {
        // The code lock lasts until the customer is saved.
        return DB::transaction(function () use ($customer, $data) {
            $customer ??= new Customer;
            if (blank($data['code'] ?? null)) {
                $data['code'] = $customer->exists ? $customer->code : $this->generateCode->handle();
            }
            $data['code'] = strtoupper(trim($data['code']));
            $customer->fill($data)->save();

            return $customer;
        });
    }
}
