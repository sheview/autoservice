<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Asset\Actions\CountCustomerAssets;
use App\Modules\Contract\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a customer that has no contracts and no assets.
 */
class DeleteCustomer
{
    public function __construct(private CountCustomerAssets $countAssets) {}

    public function handle(Customer $customer): void
    {
        if ($customer->contracts()->exists() || $this->countAssets->handle($customer->id) > 0) {
            throw ValidationException::withMessages(['customer' => __('contract.customers.in_use')]);
        }

        $customer->delete();
    }
}
