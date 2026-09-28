<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Number of (non-deleted) assets of a customer, for the Contract module.
 */
class CountCustomerAssets
{
    public function handle(int $customerId): int
    {
        return Asset::where('customer_id', $customerId)->count();
    }
}
