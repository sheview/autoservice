<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;

/**
 * Whether the customer must sign when a job is closed, for other modules: the contract decides if
 * it says so, else the customer; no customer, no signature.
 */
class SignatureRequirement
{
    public function handle(?int $customerId, ?int $contractId): bool
    {
        $byContract = $contractId ? Contract::query()->whereKey($contractId)->value('require_signature') : null;
        if ($byContract !== null) {
            return (bool) $byContract;
        }

        return $customerId ? (bool) Customer::query()->whereKey($customerId)->value('require_signature') : false;
    }
}
