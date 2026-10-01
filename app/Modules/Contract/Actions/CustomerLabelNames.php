<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;

/**
 * The name to print for each customer where space is short (QR labels): its short name, or the
 * full name when it has none. For other modules; deleted customers included (old records).
 */
class CustomerLabelNames
{
    /**
     * @return array<int, string> id => name
     */
    public function handle(): array
    {
        return Customer::query()->withTrashed()->get(['id', 'name', 'short_name'])
            ->mapWithKeys(fn (Customer $customer) => [$customer->id => filled($customer->short_name) ? $customer->short_name : $customer->name])
            ->all();
    }
}
