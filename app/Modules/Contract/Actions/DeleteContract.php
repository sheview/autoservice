<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;

/**
 * Soft-deletes a contract. Its SLA, covered assets and files stay (the contract can be restored).
 */
class DeleteContract
{
    public function handle(Contract $contract): void
    {
        $contract->delete();
    }
}
